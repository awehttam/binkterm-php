#!/usr/bin/env python3
"""
Minimal telnet client for PubTerm with reliable NAWS/SIGWINCH support.

Used by pubterm.sh when available. Handles ECHO, SGA, NAWS, and TTYPE
negotiation and sends a new NAWS subnegotiation whenever the pty is resized.
"""
import fcntl
import os
import select
import signal
import socket
import struct
import sys
import termios

HOST = os.environ.get('PUBTERM_HOST', '127.0.0.1')
PORT = int(os.environ.get('PUBTERM_PORT', '2323'))

# Telnet constants
IAC  = 255
SB   = 250
SE   = 240
WILL = 251
WONT = 252
DO   = 253
DONT = 254
ECHO  = 1
SGA   = 3
NAWS  = 31
TTYPE = 24


def winsize():
    try:
        buf = fcntl.ioctl(sys.stdin.fileno(), termios.TIOCGWINSZ, b'\x00' * 8)
        rows, cols = struct.unpack('HHHH', buf)[:2]
        return cols, rows
    except Exception:
        return 80, 24


def make_naws_packet():
    cols, rows = winsize()

    def esc(val):
        hi, lo = val >> 8, val & 0xFF
        out = [hi if hi != IAC else IAC, IAC] if hi == IAC else [hi]
        out += [lo if lo != IAC else IAC, IAC] if lo == IAC else [lo]
        return bytes(out)

    return bytes([IAC, SB, NAWS]) + esc(cols) + esc(rows) + bytes([IAC, SE])


_sock = None


def send_naws(_sig=None, _frame=None):
    if _sock:
        try:
            _sock.sendall(make_naws_packet())
        except Exception:
            pass


def handle_option(sock, cmd, opt):
    if cmd == DO:
        if opt == NAWS:
            sock.sendall(bytes([IAC, WILL, NAWS]))
            send_naws()
        elif opt == TTYPE:
            sock.sendall(bytes([IAC, WILL, TTYPE]))
        else:
            sock.sendall(bytes([IAC, WONT, opt]))
    elif cmd == WILL:
        if opt in (ECHO, SGA):
            sock.sendall(bytes([IAC, DO, opt]))
        else:
            sock.sendall(bytes([IAC, DONT, opt]))


def process_server(sock, data, buf):
    buf += data
    out = bytearray()
    i = 0
    while i < len(buf):
        b = buf[i]
        if b != IAC:
            out.append(b)
            i += 1
            continue
        if i + 1 >= len(buf):
            break  # incomplete — wait for more data
        b2 = buf[i + 1]
        if b2 == IAC:
            out.append(IAC)
            i += 2
            continue
        if b2 in (WILL, WONT, DO, DONT):
            if i + 2 >= len(buf):
                break
            handle_option(sock, b2, buf[i + 2])
            i += 3
            continue
        if b2 == SB:
            end = buf.find(bytes([IAC, SE]), i + 2)
            if end == -1:
                break  # incomplete subnegotiation — wait
            opt = buf[i + 2]
            sb_data = buf[i + 3:end]
            if opt == TTYPE and sb_data and sb_data[0] == 1:
                ttype = b'xterm-256color'
                sock.sendall(bytes([IAC, SB, TTYPE, 0]) + ttype + bytes([IAC, SE]))
            i = end + 2
            continue
        # Unknown two-byte command — skip
        i += 2
    if out:
        os.write(sys.stdout.fileno(), bytes(out))
    return buf[i:]


def main():
    global _sock
    _sock = socket.create_connection((HOST, PORT))
    _sock.setblocking(False)

    signal.signal(signal.SIGWINCH, send_naws)

    stdin_fd  = sys.stdin.fileno()
    stdout_fd = sys.stdout.fileno()  # noqa: F841 — used by process_server via os.write
    buf = bytearray()

    try:
        while True:
            try:
                r, _, _ = select.select([_sock, stdin_fd], [], [], 1.0)
            except (InterruptedError, OSError):
                continue

            for fd in r:
                if fd is _sock:
                    try:
                        data = _sock.recv(4096)
                    except BlockingIOError:
                        continue
                    if not data:
                        return
                    buf = process_server(_sock, data, buf)
                elif fd == stdin_fd:
                    try:
                        data = os.read(stdin_fd, 256)
                    except OSError:
                        return
                    if not data:
                        return
                    _sock.sendall(data.replace(bytes([IAC]), bytes([IAC, IAC])))
    except (KeyboardInterrupt, SystemExit):
        pass
    finally:
        _sock.close()


if __name__ == '__main__':
    main()
