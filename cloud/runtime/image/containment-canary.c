#define _GNU_SOURCE
#include <errno.h>
#include <fcntl.h>
#include <linux/fs.h>
#include <stdint.h>
#include <stdio.h>
#include <string.h>
#include <sys/ioctl.h>
#include <sys/socket.h>
#include <sys/syscall.h>
#include <unistd.h>

/* Explicit compat request: _IOW('f', 2, int32_t). */
#define DUO_FS_IOC32_SETFLAGS 0x40046602UL

static int denied(int fd, unsigned long request, void *argument) {
    errno = 0;
    return ioctl(fd, request, argument) == -1 && errno == EPERM;
}

static int denied_socket(int domain) {
    errno = 0;
    int fd = socket(domain, SOCK_STREAM | SOCK_CLOEXEC, 0);
    if (fd >= 0) {
        close(fd);
        return 0;
    }
    return errno == EPERM;
}

int main(int argc, char **argv) {
    if (argc != 2) {
        return 64;
    }
    int fd = open(argv[1], O_CREAT | O_RDWR | O_CLOEXEC, 0600);
    if (fd < 0) {
        return 65;
    }
    struct fsxattr attributes;
    memset(&attributes, 0, sizeof(attributes));
    long native_flags = 0;
    uint32_t compat_flags = 0;
    if (!denied(fd, FS_IOC_FSSETXATTR, &attributes)
        || !denied(fd, FS_IOC_SETFLAGS, &native_flags)
        || !denied(fd, DUO_FS_IOC32_SETFLAGS, &compat_flags)) {
        close(fd);
        return 66;
    }
    close(fd);

    int pipe_fds[2];
    int available = 0;
    if (pipe2(pipe_fds, O_CLOEXEC) != 0
        || write(pipe_fds[1], "x", 1) != 1
        || ioctl(pipe_fds[0], FIONREAD, &available) != 0
        || available != 1) {
        return 67;
    }
    close(pipe_fds[0]);
    close(pipe_fds[1]);
    if (!denied_socket(38) || !denied_socket(40)) {
        return 68;
    }
#if defined(__i386__) && defined(__NR_socketcall)
    unsigned long socket_arguments[3] = {1, SOCK_STREAM | SOCK_CLOEXEC, 0};
    errno = 0;
    if (syscall(__NR_socketcall, 1, socket_arguments) != -1 || errno != EPERM) {
        return 69;
    }
#endif
    if (fputs("duo-cloud-seccomp-ioctl-canary/v1\n", stdout) < 0) {
        return 70;
    }
    return 0;
}
