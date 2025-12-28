# Script doi ten file anh cho Overleaf
# Chay script nay trong thu muc goc cua du an

$sourceFolder = "Anh Do An"
$destFolder = "images"

# Tao thu muc images neu chua co
if (!(Test-Path $destFolder)) {
    New-Item -ItemType Directory -Path $destFolder
}

# Bang anh xa ten file
$mapping = @{
    "Giao dien trang chu.png" = "trangchu.png"
    "Giao dien dang ki.png" = "dangky.png"
    "Giao dien dang nhap.png" = "dangnhap.png"
    "Giao dien bang dieu khien trang benh nhan.png" = "dashboard_benhnhan.png"
    "menu trang benh nhan.png" = "menu_benhnhan.png"
    "Giao dien tao tin tuyen .png" = "taotintuyen.png"
    "Giao dien danh gia trang benh nhan.png" = "danhgia.png"
    "Giao dien yeu thich trang benh nhan.png" = "yeuthich_benhnhan.png"
    "Giao dien lich su nhan viec trang benh nhan.png" = "lichsu_benhnhan.png"
    "Giao dien bang dieu khien trang sinh vien.png" = "dashboard_sinhvien.png"
    "Menu trang sinh vien.png" = "menu_sinhvien.png"
    "Giao dien form cap nhat ho so trang sinh vien.png" = "capnhathoso.png"
    "Giao dien form xin xac thuc tai khoan.png" = "xinxacthuc.png"
    "Giao dien form xin cap quyen dang tin.png" = "xincapquyen.png"
    "Giao dien thong bao xin cap quyen dang tin.png" = "thongbao_capquyen.png"
    "Giao dien tao tin ung tuyen.png" = "taotinungtuyen.png"
    "Giao dien lich su nhan viec trang sinh vien.png" = "lichsu_sinhvien.png"
    "Giao dien hoi thoai.png" = "hoithoai.png"
    "Giao dien trang ban be.png" = "banbe.png"
    "Giao dien trang bai tuyen yeu thich.png" = "yeuthich.png"
    "Giao dien thong bao.png" = "thongbao.png"
    "Giao dien trang ho tro tai khoan.png" = "hotro.png"
    "Giao dien bang dieu khien trang quan tri.png" = "admin_dashboard.png"
    "Menu trang quan tri.png" = "menu_admin.png"
    "Giao dien quan ly nguoi dung.png" = "admin_users.png"
    "Giao dien quan ly bai viet.png" = "admin_posts.png"
    "Giao dien xac minh sinh vien.png" = "admin_xacminh.png"
    "Giao dien dang tin tuyen trang quan tri.png" = "admin_tintuyen.png"
    "Giao dien dang tin ung tuyen trang quan tri.png" = "admin_tinungtuyen.png"
    "Giao dien xem khieu nai.png" = "admin_khieunai.png"
    "Giao den gui thong bao .png" = "admin_guithongbao.png"
    "Giao dien yeu thich trang quan tri.png" = "admin_yeuthich.png"
    "Giao dien trang lich su nhan viec.png" = "admin_lichsu.png"
}

Write-Host "Script created. Please rename files manually or use the mapping table in DACN.tex"
