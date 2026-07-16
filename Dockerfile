FROM php:8.2-apache

# driver PostgreSQL (usado em produção via DATABASE_URL)
RUN apt-get update \
    && apt-get install -y --no-install-recommends libpq-dev libonig-dev \
    && docker-php-ext-install pdo_pgsql mbstring \
    && rm -rf /var/lib/apt/lists/*

COPY . /var/www/html/

# Render injeta a porta via $PORT; o Apache lê ${PORT} do ambiente
ENV PORT=10000
RUN sed -i 's/Listen 80/Listen ${PORT}/' /etc/apache2/ports.conf \
    && sed -i 's/<VirtualHost \*:80>/<VirtualHost *:${PORT}>/' /etc/apache2/sites-available/000-default.conf

EXPOSE 10000
CMD ["apache2-foreground"]
