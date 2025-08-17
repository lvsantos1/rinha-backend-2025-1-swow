FROM alpine:3.22 AS build

ARG HAPROXY_VERSION=3.1.0

RUN apk add --no-cache \
    build-base \
    linux-headers \
    pcre2-dev \
    openssl-dev \
    make \
    curl \
    tar

ENV CFLAGS="-O3 -march=znver2 \
            -fomit-frame-pointer -ffunction-sections -fdata-sections \
            -fstrict-aliasing -flto=auto"
ENV CXXFLAGS="${CFLAGS}"
ENV LDFLAGS="-Wl,-O1 -Wl,--as-needed -Wl,--gc-sections"

RUN curl -sSL https://www.haproxy.org/download/${HAPROXY_VERSION%.*}/src/haproxy-${HAPROXY_VERSION}.tar.gz \
    | tar xz && \
    cd haproxy-${HAPROXY_VERSION} && \
    make TARGET=linux-musl \
         USE_PCRE2=1 \
         USE_PCRE2_JIT=1 \
         USE_OPENSSL=1 \
         USE_ZLIB= \
         USE_LUA= \
         NO_LUA=1 \
         USE_TFO=1 \
         USE_NS=1 \
         USE_THREAD=1 \
         USE_POLL=1 \
         USE_EPOLL=1 \
         USE_LINUX_TPROXY=1 \
         USE_LINUX_SPLICE=1 \
         ADDLIB=-latomic \
         -j$(nproc) && \
    make install-bin

FROM alpine:3.22

RUN apk add --no-cache \
    pcre2 \
    openssl

COPY --from=build /usr/local/sbin/haproxy /usr/local/sbin/haproxy

RUN mkdir -p /etc/haproxy

EXPOSE 80

CMD ["/usr/local/sbin/haproxy", "-f", "/etc/haproxy/haproxy.cfg", "-db"]
