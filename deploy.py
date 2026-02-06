#!/usr/bin/env python3
"""
カクレマ サーバーデプロイスクリプト

使い方:
    python3 deploy.py [ファイルパス...]

例:
    python3 deploy.py public_html/pages/booking/index.php
    python3 deploy.py public_html/pages/settings/store.php public_html/pages/booking/confirm.php
    python3 deploy.py database/009_add_sales_area_details.sql
"""

import pty
import os
import sys
import select
import subprocess
import time
import re
import shlex
import getpass

# 設定
LOCAL_BASE = '/mnt/c/ai/developer/management_system'
REMOTE_BASE = '~/xs151334.xsrv.jp/public_html/kakurema'
SSH_HOST = 'kakurema'
SSH_KEY = os.path.expanduser('~/.ssh/keys/kakurema.key')

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

def upload_file(local_path, env):
    """単一ファイルをアップロード"""
    # ローカルパスを正規化
    if local_path.startswith(LOCAL_BASE):
        relative_path = local_path[len(LOCAL_BASE)+1:]
    else:
        relative_path = local_path

    local_full = os.path.join(LOCAL_BASE, relative_path)

    if not os.path.exists(local_full):
        print(f"ERROR: File not found: {local_full}")
        return False

    # リモートパスを計算（public_html/ はサーバーの kakurema/ 直下に対応）
    if relative_path.startswith('public_html/'):
        remote_relative = relative_path[len('public_html/'):]
    else:
        remote_relative = relative_path

    remote_full = f"{SSH_HOST}:{REMOTE_BASE}/{remote_relative}"

    # リモートディレクトリを作成
    remote_dir = os.path.dirname(f"{REMOTE_BASE}/{remote_relative}")

    print(f"Uploading: {relative_path}")
    print(f"  Local:  {local_full}")
    print(f"  Remote: {REMOTE_BASE}/{remote_relative}")

    # ディレクトリ作成（コマンドインジェクション対策）
    safe_remote_dir = shlex.quote(remote_dir)
    subprocess.run(
        ['ssh', SSH_HOST, f'mkdir -p -- {safe_remote_dir}'],
        env=env, capture_output=True
    )

    # rsync実行
    master, slave = pty.openpty()
    proc = subprocess.Popen(
        ['rsync', '-avz', '--no-perms', '--no-owner', '--no-group',
         local_full, remote_full],
        stdin=slave, stdout=slave, stderr=slave,
        close_fds=True, env=env
    )
    os.close(slave)

    while True:
        try:
            r, w, e = select.select([master], [], [], 30)
            if master in r:
                data = os.read(master, 1024)
                if not data:
                    break
                print(data.decode('utf-8', errors='replace'), end='', flush=True)
            else:
                if proc.poll() is not None:
                    break
        except OSError:
            break

    proc.wait()
    os.close(master)

    return proc.returncode == 0

def main():
    if len(sys.argv) < 2:
        print(__doc__)
        sys.exit(1)

    files = sys.argv[1:]

    print("=== カクレマ デプロイ ===")
    print(f"Files to upload: {len(files)}")
    print()

    # パスフレーズ取得
    ssh_passphrase = get_ssh_passphrase()

    try:
        env = start_ssh_agent(ssh_passphrase)
        print("SSH agent started and key added.\n")
    except Exception as e:
        print(f"ERROR: {e}")
        sys.exit(1)

    success = 0
    failed = 0

    for f in files:
        if upload_file(f, env):
            success += 1
        else:
            failed += 1
        print()

    # Cleanup agent
    subprocess.run(['kill', env['SSH_AGENT_PID']], capture_output=True)

    print("=== Summary ===")
    print(f"Success: {success}, Failed: {failed}")

    sys.exit(0 if failed == 0 else 1)

if __name__ == '__main__':
    main()
