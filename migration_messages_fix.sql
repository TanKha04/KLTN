-- Migration: Thêm cột post_id vào bảng messages
-- Chạy script này để sửa lỗi cột thiếu

-- Thêm cột post_id (bỏ qua lỗi nếu đã tồn tại)
ALTER TABLE messages ADD COLUMN post_id INT(11) DEFAULT NULL AFTER receiver_id;

-- Thêm index cho post_id
CREATE INDEX idx_messages_post_id ON messages (post_id);
