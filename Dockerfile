# ===============================
# Dockerfile — PHP HLS → ADTS Stream Proxy (fixed link)
# ===============================

# 1️⃣ Base image nhẹ
FROM php:8.2-cli-alpine

# 2️⃣ Set working directory
WORKDIR /app

# 3️⃣ Cài curl (dùng tải m3u8 và segment)
RUN apk add --no-cache curl

# 4️⃣ Copy file PHP stream
COPY radio_render.php .

# 5️⃣ Expose port 8080
EXPOSE 8080

# 6️⃣ Chạy PHP built-in web server
CMD ["php", "-S", "0.0.0.0:8080", "radio_render.php"]
