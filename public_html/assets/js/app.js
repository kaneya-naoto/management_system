/**
 * カクレマ管理システム - JavaScript
 */

// CSRF トークン取得
function getCsrfToken() {
    const input = document.querySelector('input[name="_token"]');
    return input ? input.value : '';
}

// 確認ダイアログ付きフォーム送信
function confirmSubmit(form, message) {
    if (confirm(message || '本当に実行しますか？')) {
        form.submit();
    }
    return false;
}

// 削除確認
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('[data-confirm]').forEach(function(el) {
        el.addEventListener('click', function(e) {
            if (!confirm(this.dataset.confirm)) {
                e.preventDefault();
            }
        });
    });
});
