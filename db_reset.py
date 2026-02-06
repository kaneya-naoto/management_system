#!/usr/bin/env python3
"""
カクレマ データリセットスクリプト

テーブル構造（スキーマ）は維持したまま、データだけ全削除 → シードデータ再投入。
SSH経由でstdinにSQLを流し込む方式でバッククォート問題を回避。

使い方:
    python3 db_reset.py
    python3 db_reset.py --yes    # 確認スキップ

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
LOCAL_BASE = '/mnt/c/ai/developer/management_system'
SSH_HOST = 'kakurema'
SSH_KEY = os.path.expanduser('~/.ssh/keys/kakurema.key')
DB_USER = 'xs151334_admin'
DB_PASS = 's6RTMM~cB|Dw'
DB_NAME = 'xs151334_kakurema'


def get_ssh_passphrase():
    passphrase = os.environ.get('KAKUREMA_SSH_PASSPHRASE')
    if not passphrase:
        passphrase = getpass.getpass('SSH Key Passphrase: ')
    return passphrase


def start_ssh_agent(ssh_passphrase):
    agent_output = subprocess.check_output(['ssh-agent', '-s'], text=True)
    auth_sock_match = re.search(r'SSH_AUTH_SOCK=([^;]+);', agent_output)
    agent_pid_match = re.search(r'SSH_AGENT_PID=(\d+);', agent_output)

    if not auth_sock_match or not agent_pid_match:
        raise Exception("Failed to start ssh-agent")

    env = os.environ.copy()
    env['SSH_AUTH_SOCK'] = auth_sock_match.group(1)
    env['SSH_AGENT_PID'] = agent_pid_match.group(1)

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


def run_mysql_via_stdin(sql, env, timeout=120):
    """SSH経由でMySQLにSQLをstdinで流し込む（シェル解釈問題を回避）"""
    mysql_cmd = f"mysql -u {DB_USER} -p'{DB_PASS}' {DB_NAME}"
    proc = subprocess.Popen(
        ['ssh', SSH_HOST, mysql_cmd],
        stdin=subprocess.PIPE,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        env=env
    )
    stdout, stderr = proc.communicate(input=sql.encode('utf-8'), timeout=timeout)
    return stdout.decode('utf-8'), stderr.decode('utf-8'), proc.returncode


def step_print(step_num, total, description):
    print(f"\n{'='*60}")
    print(f"  Step {step_num}/{total}: {description}")
    print(f"{'='*60}")


def main():
    total_steps = 4

    print("=" * 60)
    print("  カクレマ データリセット（スキーマ維持）")
    print("=" * 60)
    print()
    print("WARNING: 全テーブルのデータを削除してシードデータを再投入します！")
    print("テーブル構造（スキーマ）はそのまま維持されます。")
    print()

    if '--yes' not in sys.argv:
        confirm = input("続行しますか？ (yes/no): ").strip().lower()
        if confirm != 'yes':
            print("中止しました。")
            sys.exit(0)
    else:
        print("--yes フラグにより自動承認")

    ssh_passphrase = get_ssh_passphrase()

    try:
        env = start_ssh_agent(ssh_passphrase)
        print("SSH agent started.\n")
    except Exception as e:
        print(f"ERROR: {e}")
        sys.exit(1)

    try:
        # ========================================
        # Step 1: 全テーブルのデータをTRUNCATE
        # ========================================
        step_print(1, total_steps, "全テーブルのデータをTRUNCATE")

        truncate_sql = """
SET FOREIGN_KEY_CHECKS = 0;
TRUNCATE TABLE webhook_rate_limits;
TRUNCATE TABLE ipass_view_tokens;
TRUNCATE TABLE daily_notification_logs;
TRUNCATE TABLE webhook_events;
TRUNCATE TABLE line_rich_menus;
TRUNCATE TABLE line_accounts;
TRUNCATE TABLE extension_requests;
TRUNCATE TABLE application_tokens;
TRUNCATE TABLE email_logs;
TRUNCATE TABLE audit_logs;
TRUNCATE TABLE cleaner_payments;
TRUNCATE TABLE notification_logs;
TRUNCATE TABLE login_attempts;
TRUNCATE TABLE extensions;
TRUNCATE TABLE job_applications;
TRUNCATE TABLE cleaning_jobs;
TRUNCATE TABLE key_assignments;
TRUNCATE TABLE `keys`;
TRUNCATE TABLE reservations;
TRUNCATE TABLE fixed_cleaners;
TRUNCATE TABLE cleaner_stores;
TRUNCATE TABLE cleaners;
TRUNCATE TABLE password_reset_tokens;
TRUNCATE TABLE user_stores;
TRUNCATE TABLE users;
TRUNCATE TABLE sales_areas;
TRUNCATE TABLE stores;
TRUNCATE TABLE owners;
SET FOREIGN_KEY_CHECKS = 1;
"""
        stdout, stderr, rc = run_mysql_via_stdin(truncate_sql, env)
        if rc != 0:
            print(f"ERROR: {stderr}")
            sys.exit(1)
        print("  全テーブルTRUNCATE完了!")

        # ========================================
        # Step 2: シードデータ投入（002_seed_data.sql）
        # ========================================
        step_print(2, total_steps, "シードデータ投入（002_seed_data.sql）")

        seed_file = os.path.join(LOCAL_BASE, 'database', '002_seed_data.sql')
        with open(seed_file, 'r', encoding='utf-8') as f:
            seed_sql = f.read()

        stdout, stderr, rc = run_mysql_via_stdin(seed_sql, env)
        if rc != 0:
            print(f"ERROR: {stderr}")
            sys.exit(1)
        print("  シードデータ投入完了!")

        # ========================================
        # Step 3: OWNERユーザーの owner_id 設定
        # ========================================
        step_print(3, total_steps, "OWNERユーザーの owner_id 設定")

        owner_sql = "UPDATE users SET owner_id = 1 WHERE role = 'OWNER' AND owner_id IS NULL;"
        stdout, stderr, rc = run_mysql_via_stdin(owner_sql, env)
        if rc != 0:
            print(f"ERROR: {stderr}")
            sys.exit(1)
        print("  owner_id 設定完了!")

        # ========================================
        # Step 4: 結果確認
        # ========================================
        step_print(4, total_steps, "結果確認")

        # テーブル数確認
        stdout, stderr, rc = run_mysql_via_stdin("SHOW TABLES;", env)
        if rc == 0:
            tables = [l for l in stdout.strip().split('\n') if l and 'Tables_in' not in l]
            print(f"  テーブル数: {len(tables)}")

        # 主要テーブルのデータ件数
        print()
        count_query = """
SELECT 'owners' AS tbl, COUNT(*) AS cnt FROM owners
UNION ALL SELECT 'stores', COUNT(*) FROM stores
UNION ALL SELECT 'sales_areas', COUNT(*) FROM sales_areas
UNION ALL SELECT 'users', COUNT(*) FROM users
UNION ALL SELECT '`keys`', COUNT(*) FROM `keys`
UNION ALL SELECT 'cleaners', COUNT(*) FROM cleaners
UNION ALL SELECT 'cleaner_stores', COUNT(*) FROM cleaner_stores
UNION ALL SELECT 'fixed_cleaners', COUNT(*) FROM fixed_cleaners
UNION ALL SELECT 'reservations', COUNT(*) FROM reservations
UNION ALL SELECT 'cleaning_jobs', COUNT(*) FROM cleaning_jobs
UNION ALL SELECT 'job_applications', COUNT(*) FROM job_applications
UNION ALL SELECT 'user_stores', COUNT(*) FROM user_stores;
"""
        stdout, stderr, rc = run_mysql_via_stdin(count_query, env)
        if rc == 0:
            print("  データ件数:")
            for line in stdout.strip().split('\n'):
                if line and 'tbl' not in line:
                    parts = line.split('\t')
                    if len(parts) == 2:
                        print(f"    {parts[0]}: {parts[1]}件")

        print()
        print("=" * 60)
        print("  データリセット完了!")
        print("  ログイン: admin@kakurema.jp / password123")
        print("=" * 60)

    finally:
        subprocess.run(['kill', env['SSH_AGENT_PID']], capture_output=True)


if __name__ == '__main__':
    main()
