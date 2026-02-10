<script src="https://cdn.jsdelivr.net/npm/qrcode@1.5.1/build/qrcode.min.js"></script>
<script>
// 営業区分削除
function deleteArea() {
    if (!confirm('この営業区分を削除しますか？')) return;
    document.getElementById('deleteAreaId').value = document.getElementById('editAreaId').value;
    document.getElementById('deleteAreaForm').submit();
}

// 24時間営業トグル
function toggle24hMode(is24h) {
    var fields = document.getElementById('businessHoursFields');
    fields.style.display = is24h ? 'none' : '';
}

// 延長URLをコピー
function copyExtensionUrl() {
    var urlInput = document.getElementById('extensionUrl');
    var copyIcon = document.getElementById('copyExtUrlIcon');

    navigator.clipboard.writeText(urlInput.value).then(function() {
        copyIcon.className = 'bi bi-check';
        setTimeout(function() {
            copyIcon.className = 'bi bi-clipboard';
        }, 2000);
    }).catch(function() {
        urlInput.select();
        document.execCommand('copy');
        copyIcon.className = 'bi bi-check';
        setTimeout(function() {
            copyIcon.className = 'bi bi-clipboard';
        }, 2000);
    });
}

// QRコードをダウンロード
function downloadQRCode() {
    var canvas = document.querySelector('#qrCodeContainer canvas');
    if (!canvas) return;

    var areaName = document.getElementById('editAreaName').value || 'room';
    var link = document.createElement('a');
    link.download = 'qr_' + areaName + '.png';
    link.href = canvas.toDataURL('image/png');
    link.click();
}

// QRコードを生成
function generateQRCode(roomCode) {
    var container = document.getElementById('qrCodeContainer');
    var urlInput = document.getElementById('extensionUrl');
    var qrSection = document.getElementById('qrCodeSection');

    if (!roomCode) {
        qrSection.style.display = 'none';
        return;
    }

    qrSection.style.display = 'block';
    var url = '<?= rtrim(APP_URL, "/") ?>/extend/room/' + roomCode;
    urlInput.value = url;

    // 既存のQRコードをクリア
    container.innerHTML = '';

    // QRコード生成
    QRCode.toCanvas(url, {
        width: 200,
        margin: 2,
        color: { dark: '#000000', light: '#ffffff' }
    }, function(error, canvas) {
        if (error) {
            console.error(error);
            container.innerHTML = '<p class="text-danger">QRコード生成エラー</p>';
            return;
        }
        container.appendChild(canvas);
    });
}

// 予約URLをコピー
function copyBookingUrl() {
    var urlInput = document.getElementById('bookingUrl');
    var copyIcon = document.getElementById('copyIcon');

    navigator.clipboard.writeText(urlInput.value).then(function() {
        copyIcon.className = 'bi bi-check';
        setTimeout(function() {
            copyIcon.className = 'bi bi-clipboard';
        }, 2000);
    }).catch(function() {
        urlInput.select();
        document.execCommand('copy');
        copyIcon.className = 'bi bi-check';
        setTimeout(function() {
            copyIcon.className = 'bi bi-clipboard';
        }, 2000);
    });
}

document.addEventListener('DOMContentLoaded', function() {
    // 編集モーダル: データ設定
    var editModal = document.getElementById('editAreaModal');
    editModal.addEventListener('show.bs.modal', function(event) {
        var button = event.relatedTarget;
        document.getElementById('editAreaId').value = button.dataset.id;
        document.getElementById('editAreaName').value = button.dataset.name;
        document.getElementById('editAreaHourlyRate').value = button.dataset.hourlyRate || 2500;
        document.getElementById('editAreaCapacity').value = button.dataset.capacity || 4;
        document.getElementById('editAreaDescription').value = button.dataset.description || '';
        document.getElementById('editAreaActive').checked = button.dataset.active === '1';

        // 清掃所要時間
        var cleaningDuration = button.dataset.cleaningDuration || '';
        document.getElementById('editAreaCleaningDuration').value = cleaningDuration;

        // QRコード生成
        var roomCode = button.dataset.roomCode || '';
        generateQRCode(roomCode);
    });

    // 保存時バリデーション: min <= max
    var storeForm = document.getElementById('storeSettingsForm');
    if (storeForm) {
        storeForm.addEventListener('submit', function(e) {
            var minEl = document.getElementById('minDurationHours');
            var maxEl = document.getElementById('maxDurationHours');
            if (minEl && maxEl) {
                var minVal = parseInt(minEl.value, 10);
                var maxVal = parseInt(maxEl.value, 10);
                if (minVal > maxVal) {
                    e.preventDefault();
                    alert('最小利用時間は最大利用時間以下に設定してください');
                    minEl.focus();
                }
            }
        });
    }
});
</script>
