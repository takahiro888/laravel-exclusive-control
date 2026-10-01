# =============================================================================
# Laravel 実行用 PHP-FPM イメージ
# =============================================================================
FROM php:8.4-fpm

ARG UID=1000
ARG GID=1000

# pdo_mysql : Laravel から MySQL に接続するために必須
# zip/unzip : composer がパッケージを展開するために必要
# bcmath    : Laravel の一部機能で利用
RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip libzip-dev default-mysql-client \
    && docker-php-ext-install pdo_mysql zip bcmath \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# ホストに PHP/Composer を入れなくて済むよう、コンテナ内に composer を同梱する
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

COPY php.ini /usr/local/etc/php/conf.d/zz-custom.ini

# ホストと同じ UID/GID のユーザーで PHP-FPM / artisan を動かす
# www-data のホーム(/var/www)を書き込み可能にする。
# これが無いと artisan tinker が設定ファイルを保存できず失敗する（後の Phase で検証に使う）
RUN groupmod -o -g ${GID} www-data && usermod -o -u ${UID} -g ${GID} www-data \
    && chown www-data:www-data /var/www

WORKDIR /var/www/html
USER www-data
