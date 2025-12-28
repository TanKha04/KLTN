# Script copy và đổi tên ảnh từ "Ảnh Đồ Án" sang thư mục images

# Tạo thư mục images nếu chưa có
if (!(Test-Path "images")) {
    New-Item -ItemType Directory -Path "images"
}

# Danh sách ánh xạ tên file
$mappings = @{
    "Giao diện trang chủ.png" = "trangchu.png"
    "Giao diện đăng kí.png" = "dangky.png"
    "Giao diện đăng nhập.png" = "dangnhap.png"
    "Giao diện bảng điều khiển trang bệnh nhân.png" = "dashboard_benhnhan.png"
    "menu trang bệnh nhân.png" = "menu_benhnhan.png"
    "Giao diện tạo tin tuyển .png" = "taotintuyen.png"
    "Giao diện đánh giá trang bệnh nhân.png" = "danhgia.png"
    "Giao diện yêu thích trang bệnh nhân.png" = "yeuthich_benhnhan.png"
    "Giao diện lịch sử nhận việc trang bệnh nhân.png" = "lichsu_benhnhan.png"
    "Giao diện bảng điều khiển trang sinh viên.png" = "dashboard_sinhvien.png"
    "Menu trang sinh viên.png" = "menu_sinhvien.png"
    "Giao diện form cập nhật hồ sơ trang sinh viên.png" = "capnhathoso.png"
    "Giao diện form xin xác thực tài khoản.png" = "xinxacthuc.png"
    "Giao diện form xin cấp quyền đăng tin.png" = "xincapquyen.png"
    "Giao diện thông báo xin cấp quyền đăng tin.png" = "thongbao_capquyen.png"
    "Giao diện tạo tin ứng tuyển.png" = "taotinungtuyen.png"
    "Giao diện lịch sử nhận việc trang sinh viên.png" = "lichsu_sinhvien.png"
    "Giao diện hội thoại.png" = "hoithoai.png"
    "Giao diện trang bạn bè.png" = "banbe.png"
    "Giao diện trang bài tuyển yêu thích.png" = "yeuthich.png"
    "Giao diện thông báo.png" = "thongbao.png"
    "Giao diện trang hỗ trợ tài khoản.png" = "hotro.png"
    "Giao diện bảng điều khiển trang quản trị.png" = "admin_dashboard.png"
    "Menu trang quản trị.png" = "menu_admin.png"
    "Giao diện quản lý người dùng.png" = "admin_users.png"
    "Giao diện quản lý bài viết.png" = "admin_posts.png"
    "Giao diện xác minh sinh viên.png" = "admin_xacminh.png"
    "Giao diện đăng tin tuyển trang quản trị.png" = "admin_tintuyen.png"
    "Giao diện đăng tin ứng tuyển trang quản trị.png" = "admin_tinungtuyen.png"
    "Giao diện xem khiếu nại.png" = "admin_khieunai.png"
    "Giao dện gửi thông báo .png" = "admin_guithongbao.png"
    "Giao diện yêu thích trang quản trị.png" = "admin_yeuthich.png"
    "Giao diện trang lịch sử nhận việc.png" = "admin_lichsu.png"
}

# Copy và đổi tên từng file
foreach ($item in $mappings.GetEnumerator()) {
    $source = "Ảnh Đồ Án\$($item.Key)"
    $dest = "images\$($item.Value)"
    
    if (Test-Path $source) {
        Copy-Item -Path $source -Destination $dest -Force
        Write-Host "Copied: $($item.Key) -> $($item.Value)"
    } else {
        Write-Host "Not found: $source" -ForegroundColor Yellow
    }
}

# Copy file usecase và erd
if (Test-Path "usecase.SVG") {
    Copy-Item -Path "usecase.SVG" -Destination "images\usecase.png" -Force
    Write-Host "Copied: usecase.SVG -> usecase.png"
}

if (Test-Path "erd.SVG") {
    Copy-Item -Path "erd.SVG" -Destination "images\erd.png" -Force
    Write-Host "Copied: erd.SVG -> erd.png"
}

# Copy logo trường (nếu có file logo.webp, cần chuyển sang png)
if (Test-Path "logo.webp") {
    Copy-Item -Path "logo.webp" -Destination "images\logo_tvu.webp" -Force
    Write-Host "Copied: logo.webp -> logo_tvu.webp (Cần chuyển sang PNG để dùng trong LaTeX)"
}

Write-Host "`nHoàn tất! Các file đã được copy vào thư mục images/"
Write-Host "Lưu ý: File SVG và WEBP cần chuyển sang PNG để sử dụng trong LaTeX"
