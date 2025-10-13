# ==============================
# Dockerfile — Fixed HLS Radio Stream (ADTS)
# ==============================

# 1️⃣ Base image nhẹ, có PHP
FROM php:8.2-cli-alpine

# 2️⃣ Tạo thư mục làm việc
WORKDIR /var/www/html

# 3️⃣ Cài curl (dùng để tải m3u8 và segment)
RUN apk add --no-cache curl

# 4️⃣ Copy file PHP stream vào container
COPY radio.php .

# 5️⃣ Expose cổng 8080 (web server)
EXPOSE 8080

# 6️⃣ Chạy PHP web server, tự phục vụ radio.php
CMD ["php", "-S", "0.0.0.0:8080", "radio.php"]
