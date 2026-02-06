# カクレマ 新基幹システム 仕様書

> 本ディレクトリには、カクレマ新基幹システムの詳細仕様がまとめられています。

---

## ディレクトリ構成

```
spec/
├── README.md                    # このファイル（仕様書インデックス）
│
├── overview/                    # システム概要
│   ├── system_overview.md       # システム全体像・コンセプト
│   ├── organization_model.md    # 組織階層・権限モデル
│   └── tech_stack.md            # 技術スタック
│
├── features/                    # 機能仕様
│   ├── reservation/
│   │   ├── reservation_flow.md  # 予約フロー
│   │   ├── reservation_ui.md    # 予約UI仕様
│   │   └── reservation_block.md # 予約ブロック（清掃時間考慮）
│   ├── key_management.md        # 鍵番号管理
│   ├── cleaning_job.md          # 清掃案件管理
│   ├── cleaning/
│   │   └── completion_report.md # 清掃完了報告
│   ├── notification/
│   │   └── auto_notification.md # 自動通知システム
│   ├── apply/
│   │   └── cleaner_job_list.md  # 清掃者向け案件一覧
│   ├── line/
│   │   ├── line_overview.md     # LINE連携概要
│   │   ├── line_registration.md # 初回登録・iPassフロー
│   │   └── line_application.md  # 応募・通知フロー
│   ├── fixed_cleaner.md         # 固定者・急募
│   ├── extension.md             # 延長対応
│   ├── shift_management.md      # シフト管理
│   ├── payment.md               # 支払い管理
│   └── kpi_dashboard.md         # 売上・KPI
│
├── db/                          # DB設計
│   ├── schema.md                # テーブル一覧・ER図
│   ├── tables/                  # 各テーブル定義（SQL含む）
│   │   ├── users.md
│   │   ├── reservations.md
│   │   ├── cleaning_jobs.md
│   │   └── cleaners.md
│   └── design_policy.md         # 設計方針
│
├── audit/
│   └── logging.md               # 操作ログ・通知履歴
│
├── schedule/
│   └── development_phases.md    # 開発フェーズ・工数表
│
└── review/
    └── codex_review.md          # Codexレビュー結果
```

---

## クイックリンク

### システム理解（最初に読む）

| ドキュメント | 内容 |
|--------------|------|
| [システム概要](overview/system_overview.md) | コンセプト、ビジネス要件、開発規模 |
| [組織・権限モデル](overview/organization_model.md) | 3階層構造、認証方式 |
| [技術スタック](overview/tech_stack.md) | 使用技術、コーディング規約 |

### 機能仕様

| ドキュメント | 内容 |
|--------------|------|
| [予約フロー](features/reservation/reservation_flow.md) | フロント予約、決済、確認メール |
| [予約UI](features/reservation/reservation_ui.md) | CMS予約管理、一覧表示 |
| [予約ブロック](features/reservation/reservation_block.md) | 清掃時間考慮、動的解除 |
| [鍵番号管理](features/key_management.md) | 鍵登録、ランダム割当、競合防止 |
| [清掃案件管理](features/cleaning_job.md) | 案件生成、状態遷移 |
| [清掃完了報告](features/cleaning/completion_report.md) | LINE/CMS完了報告、ブロック解除 |
| [自動通知](features/notification/auto_notification.md) | 当日即時通知、定点通知バッチ |
| [案件一覧（清掃者向け）](features/apply/cleaner_job_list.md) | 空き案件確認、応募 |
| [LINE概要](features/line/line_overview.md) | LINE連携の全体像 |
| [LINE登録](features/line/line_registration.md) | 初回登録、iPassフロー |
| [LINE応募](features/line/line_application.md) | 応募画面、通知 |
| [固定者・急募](features/fixed_cleaner.md) | 優先通知、急募配信 |
| [延長対応](features/extension.md) | QR延長、報酬計算 |
| [シフト管理](features/shift_management.md) | シフト表示、マージ |
| [支払い管理](features/payment.md) | 案件単位の支払い |
| [売上・KPI](features/kpi_dashboard.md) | 売上、稼働率、粗利 |

### DB設計

| ドキュメント | 内容 |
|--------------|------|
| [スキーマ概要](db/schema.md) | ER図、テーブル一覧 |
| [設計方針](db/design_policy.md) | 論理削除、ENUM、JSON型 |
| [主要テーブル](db/tables/) | 各テーブルのSQL定義 |

### プロジェクト管理

| ドキュメント | 内容 |
|--------------|------|
| [開発フェーズ](schedule/development_phases.md) | 工数表、週別スケジュール |
| [監査・ログ](audit/logging.md) | 操作ログ、通知履歴 |
| [Codexレビュー](review/codex_review.md) | 外部AIレビュー結果 |

---

## ステータス表記（統一ルール）

清掃案件のステータスはDB値（英語）を正とし、日本語を併記する：

| DB値 | 日本語 | 説明 |
|------|--------|------|
| `unassigned` | 未割当 | 案件生成直後 |
| `recruiting` | 募集中 | LINE公募中 |
| `assigned` | 確定 | 担当者決定 |
| `completed` | 完了 | 清掃完了 |
| `paid` | 支払済 | 報酬支払済 |

---

## 関連ドキュメント

- [project.md](../project.md) - プロジェクトのエントリーポイント、実装状況
- [CLAUDE.md](../CLAUDE.md) - AI開発ガイドライン

---

*Last Updated: 2026-01-28*
