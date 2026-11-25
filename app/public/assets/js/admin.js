function confirmDelete(message) {
    return confirm(message || 'Bạn chắc chắn muốn xóa dữ liệu này?');
}
function setFieldError(id, message) {
    const element = document.getElementById(id);
    if (element) element.textContent = message;
}
function clearFieldErrors() {
    document.querySelectorAll('.field-error').forEach(element => { element.textContent = ''; });
}

const loginForm = document.getElementById('loginForm');
if (loginForm) {
    loginForm.addEventListener('submit', event => {
        clearFieldErrors();
        let valid = true;
        if (!document.getElementById('username').value.trim()) {
            setFieldError('usernameError', 'Vui lòng nhập tài khoản hoặc email.');
            valid = false;
        }
        if (!document.getElementById('password').value) {
            setFieldError('passwordError', 'Vui lòng nhập mật khẩu.');
            valid = false;
        }
        if (!valid) event.preventDefault();
    });
}

const productForm = document.getElementById('productForm');
if (productForm) {
    productForm.addEventListener('submit', event => {
        clearFieldErrors();
        let valid = true;
        if (!document.getElementById('product_name').value.trim()) {
            setFieldError('productNameError', 'Tên sản phẩm không được để trống.');
            valid = false;
        }
        if (!document.getElementById('category_id').value) {
            setFieldError('categoryError', 'Vui lòng chọn danh mục.');
            valid = false;
        }
        // Đơn vị và ngưỡng tồn dùng required/min/step; server luôn kiểm tra lại.
        const image = document.getElementById('image');
        if (image && image.files.length > 0) {
            const file = image.files[0];
            if (!['image/jpeg', 'image/png', 'image/webp', 'image/gif'].includes(file.type)) {
                alert('Chỉ cho phép upload JPG, PNG, WEBP hoặc GIF.');
                valid = false;
            }
            if (file.size > 2 * 1024 * 1024) {
                alert('Dung lượng hình ảnh tối đa là 2 MB.');
                valid = false;
            }
        }
        if (!valid) event.preventDefault();
    });
}
