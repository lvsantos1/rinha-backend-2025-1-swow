FROM alpine:3.22 AS builder

ARG PHP_VERSION=PHP-8.3.24
ARG SWOW_VERSION=v1.6.0

RUN apk add --no-cache \
    git autoconf make gcc g++ \
    libtool bison re2c pkgconfig \
    openssl-dev zlib-dev curl-dev \
    linux-headers bash

WORKDIR /build

ENV CFLAGS="-O3 -march=znver2 \
            -fomit-frame-pointer -ffunction-sections -fdata-sections \
            -fstrict-aliasing -flto=auto"
ENV CXXFLAGS="${CFLAGS}"
ENV LDFLAGS="-Wl,-O1 -Wl,--as-needed -Wl,--gc-sections"

RUN git clone --branch ${PHP_VERSION} --depth 1 https://github.com/php/php-src php-src

WORKDIR /build/php-src
RUN ./buildconf --force && \
    ./configure \
        --prefix=/usr/local/php-opt \
        # XML
        --disable-libxml \
        --disable-dom \
        --disable-simplexml \
        --disable-xml \
        --disable-xmlreader \
        --disable-xmlwriter \
        # soap, html, xstl
        --disable-soap \
        --disable-xsl \
        --disable-wddx \
        # dbs
        --without-pdo-sqlite \
        --without-sqlite3 \
        --without-mysqli \
        --without-pdo-mysql \
        --without-pdo-pgsql \
        --without-pgsql \
        --without-odbc \
        --without-pdo-odbc \
        --without-pdo-firebird \
        # variados (ipc, standalone, etc)
        --disable-phar \
        --disable-posix \
        --disable-shmop \
        --disable-sysvmsg \
        --disable-sysvsem \
        --disable-sysvshm \
        --disable-ctype \
        --disable-tokenizer \
        --disable-filter \
        --disable-intl \
        --disable-exif \
        --disable-gettext \
        --disable-cgi \
        # compressão
        --disable-zip \
        --without-bz2 \
        --without-xpm \
        # debug
        --disable-debug \
        --disable-cli-debug \
        --disable-phpdbg \
        # mantém o básico
        --enable-cli \
        --with-openssl \
        --with-zlib \
        --with-curl \
        --enable-bcmath \
        --enable-shmop \
        --enable-opcache && \
    make -j$(nproc) && \
    make install

WORKDIR /build
RUN git clone --branch ${SWOW_VERSION} --depth 1 https://github.com/swow/swow.git swow-src
WORKDIR /build/swow-src/ext
RUN /usr/local/php-opt/bin/phpize && \
    ./configure --with-php-config=/usr/local/php-opt/bin/php-config && \
    make -j$(nproc) && \
    make install

FROM alpine:3.22

RUN apk add --no-cache libstdc++ openssl zlib curl

COPY --from=builder /usr/local/php-opt /usr/local/php-opt
COPY --from=builder /opt/php-preload-cache /opt/php-preload-cache

COPY ./run.ini /usr/local/php-opt/lib/php.ini

ENV PATH="/usr/local/php-opt/bin:$PATH"

ENTRYPOINT ["php"]
CMD ["-a"]
