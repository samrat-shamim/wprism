FROM wordpress:cli-php8.3

USER root
RUN apk add --no-cache openssh-server \
    && adduser -D -s /bin/sh duo \
    && passwd -d duo \
    && ssh-keygen -A \
    && mkdir -p /run/sshd /home/duo/.ssh \
    && chown -R duo:duo /home/duo \
    && chmod 0700 /home/duo/.ssh

EXPOSE 22
