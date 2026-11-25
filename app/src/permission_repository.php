<?php
require_once __DIR__ . '/auth.php';

/** Deny riêng > allow riêng > hợp quyền nhóm > mặc định từ chối. */
function permission_decide(array $groupCodes, array $overrides, string $code): bool {
    if (isset($overrides[$code])) return $overrides[$code] === 'allow';
    return in_array($code, $groupCodes, true);
}

function permission_for_user(int $userId, string $code): bool {
    $stmt = db()->prepare(
        "SELECT p.code, up.effect,
                EXISTS(SELECT 1 FROM user_roles ur
                       JOIN role_permissions rp ON rp.role_id=ur.role_id
                       WHERE ur.user_id=:group_user AND rp.permission_id=p.id) AS group_allowed
         FROM permissions p
         LEFT JOIN user_permissions up ON up.permission_id=p.id AND up.user_id=:direct_user
         WHERE p.code=:code"
    );
    $stmt->execute(['group_user'=>$userId, 'direct_user'=>$userId, 'code'=>$code]);
    $row = $stmt->fetch();
    if (!$row) return false;
    return permission_decide($row['group_allowed'] ? [$code] : [],
        $row['effect'] !== null ? [$code=>$row['effect']] : [], $code);
}

function can(string $code): bool {
    // Query current grants, rather than storing grants in the login session.
    return !empty($_SESSION['admin_id']) && permission_for_user((int)$_SESSION['admin_id'], $code);
}

function require_permission(string $code): void {
    require_admin();
    if (!can($code)) {
        http_response_code(403);
        exit('403 - Bạn không có quyền thực hiện chức năng này.');
    }
}

function access_data(int $userId = 0, int $roleId = 0): array {
    $users = db()->query('SELECT id,username,full_name FROM admins ORDER BY id')->fetchAll();
    $roles = db()->query('SELECT * FROM roles ORDER BY id')->fetchAll();
    $permissions = db()->query('SELECT * FROM permissions ORDER BY id')->fetchAll();
    $userId = $userId ?: (int)($users[0]['id'] ?? 0);
    $roleId = $roleId ?: (int)($roles[0]['id'] ?? 0);
    $stmt = db()->prepare('SELECT role_id FROM user_roles WHERE user_id=?');
    $stmt->execute([$userId]);
    $userRoleIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    $stmt = db()->prepare('SELECT permission_id,effect FROM user_permissions WHERE user_id=?');
    $stmt->execute([$userId]);
    $overrides = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    $stmt = db()->prepare('SELECT permission_id FROM role_permissions WHERE role_id=?');
    $stmt->execute([$roleId]);
    $rolePermissionIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    $stmt = db()->prepare('SELECT DISTINCT p.code FROM permissions p
        JOIN role_permissions rp ON rp.permission_id=p.id
        JOIN user_roles ur ON ur.role_id=rp.role_id WHERE ur.user_id=?');
    $stmt->execute([$userId]);
    $groupCodes = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $codeOverrides = [];
    foreach ($permissions as $permission) {
        if (isset($overrides[$permission['id']])) $codeOverrides[$permission['code']] = $overrides[$permission['id']];
    }
    $effective = [];
    foreach ($permissions as $permission) {
        $effective[$permission['code']] = permission_decide($groupCodes, $codeOverrides, $permission['code']);
    }
    return ['users'=>$users, 'roles'=>$roles, 'permissions'=>$permissions,
        'selected_user'=>$userId, 'selected_role'=>$roleId, 'user_role_ids'=>$userRoleIds,
        'user_overrides'=>$overrides, 'role_permission_ids'=>$rolePermissionIds,
        'group_codes'=>$groupCodes, 'effective'=>$effective];
}

function access_valid_ids($values, array $allowed): array {
    if (!is_array($values)) throw new InvalidArgumentException('Danh sách quyền hoặc nhóm không hợp lệ.');
    $ids = [];
    foreach ($values as $value) {
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
        if ($id === false || !in_array($id, $allowed, true)) {
            throw new InvalidArgumentException('Quyền hoặc nhóm không tồn tại.');
        }
        $ids[] = $id;
    }
    return array_values(array_unique($ids));
}

/** Called by the CSRF-checked POST /access route. Every save is atomic. */
function access_save(array $input, int $actorId): void {
    require_permission('access.manage');
    if ($actorId !== (int)($_SESSION['admin_id'] ?? 0)) throw new InvalidArgumentException('Người thao tác không hợp lệ.');
    $pdo = db();
    $pdo->beginTransaction();
    try {
        // Serialize permission edits, including edits to a shared role.
        $allUserIds = array_map('intval', $pdo->query('SELECT id FROM admins ORDER BY id FOR UPDATE')->fetchAll(PDO::FETCH_COLUMN));
        // A concurrent save may have revoked this actor while we waited for locks.
        if (!permission_for_user($actorId, 'access.manage')) throw new InvalidArgumentException('Quyền quản lý phân quyền đã bị thu hồi.');
        $roleIds = array_map('intval', $pdo->query('SELECT id FROM roles')->fetchAll(PDO::FETCH_COLUMN));
        $permissionIds = array_map('intval', $pdo->query('SELECT id FROM permissions')->fetchAll(PDO::FETCH_COLUMN));
        if (($input['mode'] ?? '') === 'user') {
            $userId = filter_var($input['user_id'] ?? '', FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
            if ($userId === false || !in_array($userId, $allUserIds, true)) throw new InvalidArgumentException('Tài khoản không tồn tại.');
            $selectedRoles = access_valid_ids($input['role_ids'] ?? [], $roleIds);
            $overrides = $input['overrides'] ?? [];
            if (!is_array($overrides)) throw new InvalidArgumentException('Quyền riêng không hợp lệ.');
            foreach ($overrides as $id=>$effect) {
                $permissionId = filter_var($id, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
                if ($permissionId === false || !in_array($permissionId, $permissionIds, true) || !in_array($effect, ['inherit','allow','deny'], true)) {
                    throw new InvalidArgumentException('Quyền riêng không hợp lệ.');
                }
            }
            $pdo->prepare('DELETE FROM user_roles WHERE user_id=?')->execute([$userId]);
            $insert = $pdo->prepare('INSERT INTO user_roles(user_id,role_id) VALUES(?,?)');
            foreach ($selectedRoles as $roleId) $insert->execute([$userId,$roleId]);
            $pdo->prepare('DELETE FROM user_permissions WHERE user_id=?')->execute([$userId]);
            $insert = $pdo->prepare('INSERT INTO user_permissions(user_id,permission_id,effect) VALUES(?,?,?)');
            foreach ($overrides as $id=>$effect) {
                if ($effect !== 'inherit') $insert->execute([$userId,(int)$id,$effect]);
            }
        } elseif (($input['mode'] ?? '') === 'role') {
            $roleId = filter_var($input['role_id'] ?? '', FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
            if ($roleId === false || !in_array($roleId, $roleIds, true)) throw new InvalidArgumentException('Nhóm không tồn tại.');
            $selectedPermissions = access_valid_ids($input['permission_ids'] ?? [], $permissionIds);
            $pdo->prepare('DELETE FROM role_permissions WHERE role_id=?')->execute([$roleId]);
            $insert = $pdo->prepare('INSERT INTO role_permissions(role_id,permission_id) VALUES(?,?)');
            foreach ($selectedPermissions as $permissionId) $insert->execute([$roleId,$permissionId]);
        } else {
            throw new InvalidArgumentException('Thao tác phân quyền không hợp lệ.');
        }
        if (!permission_for_user($actorId, 'access.manage')) {
            throw new InvalidArgumentException('Không được tự thu hồi quyền quản lý phân quyền; thay đổi đã được hoàn tác.');
        }
        $managerExists = false;
        foreach ($allUserIds as $userId) {
            if (permission_for_user($userId, 'access.manage')) { $managerExists = true; break; }
        }
        if (!$managerExists) throw new InvalidArgumentException('Hệ thống phải còn ít nhất một người quản lý phân quyền.');
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}
