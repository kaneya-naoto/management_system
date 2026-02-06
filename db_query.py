#!/usr/bin/env python3
"""
カクレマ データベースクエリ実行スクリプト

使い方:
    python3 db_query.py "SELECT * FROM users LIMIT 5;"
    python3 db_query.py < query.sql
    echo "SHOW TABLES;" | python3 db_query.py

環境変数:
    KAKUREMA_SSH_PASSPHRASE - SSHキーのパスフレーズ（省略時は対話入力）
"""

import pty
import os
import sys
import select
import subprocess
import time
import re
import getpass

# 設定
SSH_HOST = 'kakurema'
SSH_KEY = os.path.expanduser('~/.ssh/keys/kakurema.key')
DB_USER = 'xs151334_admin'
DB_PASS = 's6RTMM~cB|Dw'
DB_NAME = 'xs151334_kakurema'

def get_ssh_passphrase():
    """環境変数または対話入力からSSHパスフレーズを取得"""
    passphrase = os.environ.get('KAKUREMA_SSH_PASSPHRASE')
    if not passphrase:
        passphrase = getpass.getpass('SSH Key Passphrase: ')
    return passphrase

def start_ssh_agent(ssh_passphrase):
    """SSH agentを起動してキーを追加"""
    agent_output = subprocess.check_output(['ssh-agent', '-s'], text=True)
    auth_sock_match = re.search(r'SSH_AUTH_SOCK=([^;]+);', agent_output)
    agent_pid_match = re.search(r'SSH_AGENT_PID=(\d+);', agent_output)

    if not auth_sock_match or not agent_pid_match:
        raise Exception("Failed to start ssh-agent")

    env = os.environ.copy()
    env['SSH_AUTH_SOCK'] = auth_sock_match.group(1)
    env['SSH_AGENT_PID'] = agent_pid_match.group(1)

    # Add key
    master, slave = pty.openpty()
    proc = subprocess.Popen(
        ['ssh-add', SSH_KEY],
        stdin=slave, stdout=slave, stderr=slave,
        close_fds=True, env=env
    )
    os.close(slave)

    output = b""
    pass_sent = False
    while True:
        try:
            r, w, e = select.select([master], [], [], 10)
            if master in r:
                data = os.read(master, 1024)
                if not data:
                    break
                output += data
                if b'passphrase' in output.lower() and not pass_sent:
                    time.sleep(0.1)
                    os.write(master, (ssh_passphrase + '\n').encode())
                    pass_sent = True
                    output = b""
            else:
                if proc.poll() is not None:
                    break
        except OSError:
            break

    proc.wait()
    os.close(master)

    if proc.returncode != 0:
        raise Exception("Failed to add SSH key")

    return env

def run_mysql_query(query, env):
    """SSH経由でMySQLクエリを実行"""
    # MySQLコマンドを構築
    mysql_cmd = f"mysql -u {DB_USER} -p'{DB_PASS}' {DB_NAME} -e \"{query}\""

    proc = subprocess.run(
        ['ssh', SSH_HOST, mysql_cmd],
        env=env,
        capture_output=True,
        text=True
    )

    return proc.stdout, proc.stderr, proc.returncode

def main():
    # クエリを取得
    if len(sys.argv) > 1:
        query = ' '.join(sys.argv[1:])
    elif not sys.stdin.isatty():
        query = sys.stdin.read().strip()
    else:
        print(__doc__)
        print("Enter SQL query (Ctrl+D to execute):")
        query = sys.stdin.read().strip()

    if not query:
        print("ERROR: No query provided")
        sys.exit(1)

    # パスフレーズ取得
    ssh_passphrase = get_ssh_passphrase()

    try:
        env = start_ssh_agent(ssh_passphrase)
    except Exception as e:
        print(f"ERROR: {e}", file=sys.stderr)
        sys.exit(1)

    try:
        stdout, stderr, returncode = run_mysql_query(query, env)

        if stdout:
            print(stdout)
        if stderr:
            # MySQL警告は無視（パスワード警告など）
            for line in stderr.split('\n'):
                if line and 'Warning' not in line:
                    print(line, file=sys.stderr)

        sys.exit(returncode)
    finally:
        # Cleanup agent
        subprocess.run(['kill', env['SSH_AGENT_PID']], capture_output=True)

if __name__ == '__main__':
    main()
