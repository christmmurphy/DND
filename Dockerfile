FROM php:8.2-apache

# Enable .htaccess support (data/ dir uses it to block direct access)
RUN a2enmod rewrite && \
    sed -i 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf

# Cloud Run routes to port 8080
RUN sed -i 's/Listen 80/Listen 8080/' /etc/apache2/ports.conf && \
    sed -i 's/:80>/:8080>/' /etc/apache2/sites-available/000-default.conf

WORKDIR /var/www/html

COPY index.html api.php hello.php ./

# data/ is kept outside the image so it can be mounted as a persistent volume
RUN mkdir -p /data && chown www-data:www-data /data && \
    ln -s /data /var/www/html/data

EXPOSE 8080

CMD ["apache2-foreground"]
