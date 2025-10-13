# ==============================
# Dockerfile: HLS → HTTP Stream Proxy
# ==============================

# 1️⃣ Base image: PHP with built-in web server (lightweight)
FROM php:8.2-cli-alpine

# 2️⃣ Set working directory inside the container
WORKDIR /var/www/html

# 3️⃣ Install dependencies: curl (for network streaming)
RUN apk add --no-cache curl

# 4️⃣ Copy PHP script into the container
COPY play.php .

# 5️⃣ Expose port 8080 (the web server will listen here)
EXPOSE 8080

# 6️⃣ Command: start PHP built-in web server
CMD ["php", "-S", "0.0.0.0:8080", "play.php"]
