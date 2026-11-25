<?php
function e(?string $value): string { return htmlspecialchars($value ?? "", ENT_QUOTES, "UTF-8"); }
function redirect(string $path): void { header("Location: " . $path); exit; }
function is_post(): bool { return $_SERVER["REQUEST_METHOD"] === "POST"; }
function require_post(): void {
    if (!is_post()) { http_response_code(405); header('Allow: POST'); exit('Chức năng yêu cầu POST.'); }
}
function pagination_meta(int $total, int $page = 1, int $perPage = 20): array {
    $perPage = max(1, min(100, $perPage));
    $pages = max(1, (int)ceil($total / $perPage));
    $page = max(1, min($pages, $page));
    return ['total'=>$total,'page'=>$page,'per_page'=>$perPage,'pages'=>$pages,'offset'=>($page-1)*$perPage];
}
function pagination_links(array $result, string $path, array $query = []): string {
    $page = $result['page']; $pages = $result['pages'];
    $html = '<nav class="pagination" aria-label="Phân trang"><span>' . number_format($result['total']) . ' bản ghi · Trang ' . $page . '/' . $pages . '</span>';
    $numbers = array_unique(array_merge([1, $page-1, $page, $page+1, $pages]));
    sort($numbers); $previous = 0;
    foreach ($numbers as $number) {
        if ($number < 1 || $number > $pages) continue;
        if ($previous && $number > $previous + 1) $html .= '<span>…</span>';
        $url = $path . '?' . http_build_query(array_merge($query, ['page'=>$number,'per_page'=>$result['per_page']]));
        $html .= '<a class="btn small ' . ($number === $page ? 'primary' : 'secondary') . '"' . ($number === $page ? ' aria-current="page"' : '') . ' href="' . e($url) . '">' . $number . '</a>';
        $previous = $number;
    }
    return $html . '</nav>';
}
function valid_datetime(string $value): string {
    foreach (['Y-m-d\\TH:i','Y-m-d H:i:s'] as $format) {
        $date = DateTimeImmutable::createFromFormat('!' . $format, $value);
        if ($date && $date->format($format) === $value) return $date->format('Y-m-d H:i:s');
    }
    throw new InvalidArgumentException('Thời gian không hợp lệ.');
}

function csrf_token(): string {
    if (empty($_SESSION["_csrf_token"])) $_SESSION["_csrf_token"] = bin2hex(random_bytes(32));
    return $_SESSION["_csrf_token"];
}
function csrf_field(): string {
    return '<input type="hidden" name="_csrf_token" value="' . e(csrf_token()) . '">';
}
function verify_csrf(): void {
    $token = $_POST["_csrf_token"] ?? "";
    if (empty($_SESSION["_csrf_token"]) || !hash_equals($_SESSION["_csrf_token"], $token)) {
        http_response_code(419); die("CSRF token không hợp lệ.");
    }
}
function upload_product_image(array $file): ?string {
    $config = require __DIR__ . "/config.php";
    if (!isset($file["error"]) || $file["error"] === UPLOAD_ERR_NO_FILE) return null;
    if ($file["error"] !== UPLOAD_ERR_OK) throw new RuntimeException("Upload hình ảnh thất bại.");
    if ($file["size"] > $config["app"]["upload_max_size"]) throw new RuntimeException("Dung lượng hình ảnh vượt quá giới hạn.");
    $allowed = ["image/jpeg"=>"jpg","image/png"=>"png","image/webp"=>"webp","image/gif"=>"gif"];
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file["tmp_name"]);
    if (!array_key_exists($mime, $allowed)) throw new RuntimeException("Chỉ cho phép JPG, PNG, WEBP hoặc GIF.");
    $name = date("YmdHis") . "_" . bin2hex(random_bytes(8)) . "." . $allowed[$mime];
    $dir = __DIR__ . "/../public/uploads/products";
    if (!is_dir($dir)) mkdir($dir, 0775, true);
    if (!move_uploaded_file($file["tmp_name"], $dir . "/" . $name)) throw new RuntimeException("Không thể lưu hình ảnh.");
    return "uploads/products/" . $name;
}
function delete_product_image(?string $imagePath): void {
    if (!$imagePath) return;
    $fullPath = __DIR__ . "/../public/" . $imagePath;
    if (is_file($fullPath)) unlink($fullPath);
}
