# PHP 8.2 + Apache สำหรับรันบน Render (ฟรี)
# PHP 8.2 ยังมีส่วนขยาย imap (ใช้อ่านกล่องเมล Gmail) — PHP 8.4 ขึ้นไปถูกถอดออกแล้ว
# ใช้ Debian bookworm เพราะ Debian รุ่นใหม่ (trixie) ไม่มีไลบรารี IMAP แล้ว
FROM php:8.2-apache-bookworm

RUN apt-get update \
 && apt-get install -y --no-install-recommends libc-client-dev libkrb5-dev ca-certificates \
 && docker-php-ext-configure imap --with-kerberos --with-imap-ssl \
 && docker-php-ext-install imap pdo_mysql \
 && rm -rf /var/lib/apt/lists/*

# ตั้งเวลาไทย + ปิดการโชว์ error ให้ลูกค้าเห็น
RUN { \
      echo 'date.timezone=Asia/Bangkok'; \
      echo 'display_errors=Off'; \
      echo 'log_errors=On'; \
      echo 'upload_max_filesize=2M'; \
      echo 'post_max_size=4M'; \
      echo 'max_execution_time=30'; \
    } > /usr/local/etc/php/conf.d/app.ini

# Render ส่งพอร์ตมาทางตัวแปร PORT (ค่าเริ่มต้น 10000) ให้ Apache ฟังพอร์ตนั้น
ENV PORT=10000
RUN sed -i 's/^Listen 80$/Listen ${PORT}/' /etc/apache2/ports.conf \
 && sed -i 's/<VirtualHost \*:80>/<VirtualHost *:${PORT}>/' /etc/apache2/sites-available/000-default.conf \
 && echo 'DirectoryIndex index.php' > /etc/apache2/conf-enabled/dirindex.conf

COPY . /var/www/html/
# ไม่เอาไฟล์โหมดทดลองขึ้นเว็บจริง
RUN rm -rf /var/www/html/local_test \
 && chown -R www-data:www-data /var/www/html

EXPOSE 10000
