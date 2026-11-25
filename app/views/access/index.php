<div class="panel">
    <h2>Phân quyền chức năng</h2>
    <p>Mỗi tài khoản có thể thuộc nhiều nhóm. Quyền riêng <strong>Từ chối</strong> có ưu tiên cao nhất,
       sau đó là quyền riêng <strong>Cho phép</strong>, rồi đến hợp các quyền nhóm. Không được cấp thì từ chối.</p>
    <p class="help">Quyền được kiểm tra trên máy chủ ở từng chức năng. Thay đổi áp dụng từ yêu cầu tiếp theo, không cần đăng nhập lại.</p>
    <?php if (!empty($error)): ?><div class="alert error" role="alert"><?= e($error) ?></div><?php endif; ?>
    <?php if (!empty($message)): ?><div class="alert success" role="status"><?= e($message) ?></div><?php endif; ?>
    <form method="get" action="/access" class="grid-2">
        <div class="form-group"><label for="choose-user">Tài khoản cần phân quyền</label>
            <select id="choose-user" name="user_id">
                <?php foreach ($access['users'] as $user): ?>
                    <option value="<?= (int)$user['id'] ?>" <?= $access['selected_user']===(int)$user['id'] ? 'selected' : '' ?>><?= e($user['username'].' — '.($user['full_name'] ?? '')) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group"><label for="choose-role">Nhóm cần cấu hình</label>
            <select id="choose-role" name="role_id">
                <?php foreach ($access['roles'] as $role): ?>
                    <option value="<?= (int)$role['id'] ?>" <?= $access['selected_role']===(int)$role['id'] ? 'selected' : '' ?>><?= e($role['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div><button class="btn secondary" type="submit">Xem cấu hình</button></div>
    </form>
</div>
<div class="panel">
    <h2>Nhóm và quyền riêng của tài khoản</h2>
    <form method="post" action="/access">
        <?= csrf_field() ?>
        <input type="hidden" name="mode" value="user">
        <input type="hidden" name="user_id" value="<?= $access['selected_user'] ?>">
        <input type="hidden" name="role_id" value="<?= $access['selected_role'] ?>">
        <div class="grid-2">
            <?php foreach ($access['roles'] as $role): ?>
                <label class="checkbox"><input type="checkbox" name="role_ids[]" value="<?= (int)$role['id'] ?>" <?= in_array((int)$role['id'],$access['user_role_ids'],true) ? 'checked' : '' ?>><?= e($role['name']) ?></label>
            <?php endforeach; ?>
        </div>
        <div class="table-wrap"><table class="table">
            <thead><tr><th>Chức năng</th><th>Quyền từ nhóm</th><th>Quyền riêng (UserPermission)</th><th>Hiệu lực hiện tại</th></tr></thead>
            <tbody><?php foreach ($access['permissions'] as $permission): ?>
                <?php $override=$access['user_overrides'][$permission['id']] ?? 'inherit'; ?>
                <tr><td><?= e($permission['name']) ?><small><?= e($permission['code']) ?></small></td>
                    <td><?= in_array($permission['code'],$access['group_codes'],true) ? 'Cho phép' : 'Chưa cấp' ?></td>
                    <td><select name="overrides[<?= (int)$permission['id'] ?>]" aria-label="Quyền riêng: <?= e($permission['name']) ?>">
                        <option value="inherit" <?= $override==='inherit' ? 'selected' : '' ?>>Kế thừa nhóm</option>
                        <option value="allow" <?= $override==='allow' ? 'selected' : '' ?>>Cho phép</option>
                        <option value="deny" <?= $override==='deny' ? 'selected' : '' ?>>Từ chối</option>
                    </select></td>
                    <td><span class="badge <?= $access['effective'][$permission['code']] ? 'success' : 'muted' ?>"><?= $access['effective'][$permission['code']] ? 'Được phép' : 'Từ chối' ?></span></td>
                </tr>
            <?php endforeach; ?></tbody>
        </table></div>
        <p class="help">Cột hiệu lực phản ánh cấu hình đã lưu. Bấm lưu để tính lại sau khi đổi nhóm hoặc quyền riêng.</p>
        <button class="btn primary" type="submit">Lưu quyền tài khoản</button>
    </form>
</div>
<div class="panel">
    <h2>Danh sách quyền của nhóm đã chọn</h2>
    <p>Thay đổi tại đây ảnh hưởng đến tất cả thành viên nhóm. Tài khoản có quyền riêng Từ chối vẫn bị từ chối.</p>
    <form method="post" action="/access">
        <?= csrf_field() ?>
        <input type="hidden" name="mode" value="role">
        <input type="hidden" name="user_id" value="<?= $access['selected_user'] ?>">
        <input type="hidden" name="role_id" value="<?= $access['selected_role'] ?>">
        <div class="grid-2">
            <?php foreach ($access['permissions'] as $permission): ?>
                <label class="checkbox"><input type="checkbox" name="permission_ids[]" value="<?= (int)$permission['id'] ?>" <?= in_array((int)$permission['id'],$access['role_permission_ids'],true) ? 'checked' : '' ?>><?= e($permission['name']) ?> (<?= e($permission['code']) ?>)</label>
            <?php endforeach; ?>
        </div>
        <button class="btn primary" type="submit">Lưu quyền nhóm</button>
    </form>
    <div class="hint">Bài tập tiếp: thêm/sửa/khóa tài khoản, thêm nhóm, lịch sử thay đổi quyền và phân quyền theo từng kho. Mẫu hiện tại phân quyền chức năng trên toàn hệ thống.</div>
</div>
