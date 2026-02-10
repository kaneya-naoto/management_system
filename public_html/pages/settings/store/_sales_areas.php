<?php
/**
 * 店舗設定 - 営業区分カード
 * 変数: $salesAreas, $areaErrors, $store
 */
$currentCleaningDefault = (int)($store['cleaning_time_minutes'] ?? CLEANING_TIME_MINUTES);
?>
<div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="mb-0">営業区分</h5>
        <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addAreaModal">
            <i class="bi bi-plus"></i> 追加
        </button>
    </div>
    <div class="card-body p-0">
        <?php if (!empty($areaErrors)): ?>
        <div class="alert alert-danger m-3 mb-0">
            <ul class="mb-0">
                <?php foreach ($areaErrors as $error): ?>
                <li><?= h($error) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>

        <?php if (empty($salesAreas)): ?>
        <p class="text-muted text-center py-4 mb-0">営業区分がありません</p>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th>区分名</th>
                        <th class="text-end">時間単価</th>
                        <th class="text-center">定員</th>
                        <th class="text-center">清掃時間</th>
                        <th>状態</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($salesAreas as $area): ?>
                    <tr>
                        <td><?= h($area['name']) ?></td>
                        <td class="text-end"><?= number_format($area['hourly_rate'] ?? 2500) ?>円</td>
                        <td class="text-center"><?= (int)($area['capacity'] ?? 4) ?>名</td>
                        <td class="text-center">
                            <?php if ($area['cleaning_duration_minutes'] !== null): ?>
                                <?= (int)$area['cleaning_duration_minutes'] ?>分
                            <?php else: ?>
                                <span class="text-muted"><?= $currentCleaningDefault ?>分</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($area['is_active']): ?>
                            <span class="badge bg-success">有効</span>
                            <?php else: ?>
                            <span class="badge bg-secondary">無効</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-end">
                            <button type="button" class="btn btn-sm btn-outline-primary"
                                    data-bs-toggle="modal" data-bs-target="#editAreaModal"
                                    data-id="<?= $area['id'] ?>"
                                    data-name="<?= h($area['name']) ?>"
                                    data-hourly-rate="<?= (int)($area['hourly_rate'] ?? 2500) ?>"
                                    data-capacity="<?= (int)($area['capacity'] ?? 4) ?>"
                                    data-description="<?= h($area['description'] ?? '') ?>"
                                    data-room-code="<?= h($area['room_code'] ?? '') ?>"
                                    data-active="<?= $area['is_active'] ?>"
                                    data-cleaning-duration="<?= $area['cleaning_duration_minutes'] !== null ? (int)$area['cleaning_duration_minutes'] : '' ?>">
                                編集
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>
