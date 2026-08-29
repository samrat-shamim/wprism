FROM wordpress:cli-php8.3

USER root
RUN apk add --no-cache mariadb-client openssh-server \
    && adduser -D -s /bin/sh wprism \
    && passwd -d wprism \
    && ssh-keygen -A \
    && mkdir -p /run/sshd /home/wprism/.ssh \
    && chown -R wprism:wprism /home/wprism \
    && chmod 0700 /home/wprism/.ssh

EXPOSE 22
