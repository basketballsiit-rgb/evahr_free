<div class="card mb-2 shadow-sm">
    <div class="card-header bg-white p-0" id="heading_<?php echo $m_cat['id']; ?>">
        <div class="d-flex justify-content-between align-items-center p-3 w-100" style="cursor: pointer;" data-toggle="collapse" data-target="#collapse_<?php echo $m_cat['id']; ?>" aria-expanded="false" aria-controls="collapse_<?php echo $m_cat['id']; ?>">
            <div class="d-flex align-items-center w-75">
                <span class="badge badge-primary badge-pill mr-3" style="width:30px;height:30px;line-height:22px;font-size:14px;"><?php echo htmlspecialchars($m_cat['sort_order']); ?></span>
                <div style="flex-grow: 1;">
                    <h6 class="mb-1 font-weight-bold text-dark">
                        <i class="fas fa-folder-open text-warning mr-2"></i> 
                        <?php echo htmlspecialchars($m_cat['title']); ?>
                    </h6>
                    <?php if(!empty($m_cat['description'])): ?>
                        <small class="text-muted d-block text-truncate" style="max-width: 90%;"><?php echo htmlspecialchars($m_cat['description']); ?></small>
                    <?php endif; ?>
                </div>
            </div>
            <div class="text-right d-flex align-items-center">
                <div class="mr-4 text-center">
                    <small class="text-muted d-block">คะแนนเต็ม</small>
                    <span class="text-primary font-weight-bold h5 mb-0"><?php echo number_format($m_cat['calculated_max'], 2); ?></span>
                </div>
                <!-- Action Buttons: Prevent collapse toggle when clicking buttons -->
                <div class="btn-group" onclick="event.stopPropagation();">
                    <button class="btn btn-warning btn-sm btn-edit" 
                            data-id="<?php echo $m_cat['id']; ?>" 
                            data-parent=""
                            data-title="<?php echo htmlspecialchars($m_cat['title']); ?>"
                            data-desc="<?php echo htmlspecialchars($m_cat['description']); ?>"
                            data-max="<?php echo $m_cat['max_score']; ?>"
                            data-sort="<?php echo $m_cat['sort_order']; ?>"
                            data-targetgroup="<?php echo $m_cat['target_group']; ?>"
                            data-toggle="modal" data-target="#editModal" title="แก้ไขหัวข้อหลัก">
                        <i class="fas fa-edit"></i>
                    </button>
                    <button class="btn btn-danger btn-sm btn-delete" data-id="<?php echo $m_cat['id']; ?>" title="ลบหัวข้อหลัก">
                        <i class="fas fa-trash"></i>
                    </button>
                </div>
                <i class="fas fa-chevron-down text-muted ml-3"></i>
            </div>
        </div>
    </div>

    <!-- Sub Criteria List -->
    <div id="collapse_<?php echo $m_cat['id']; ?>" class="collapse" aria-labelledby="heading_<?php echo $m_cat['id']; ?>">
        <div class="card-body bg-light p-0">
            <?php if(isset($sub_criteria[$m_cat['id']]) && count($sub_criteria[$m_cat['id']]) > 0): ?>
                <table class="table table-sm table-striped mb-0">
                    <thead class="bg-secondary text-white">
                        <tr>
                            <th width="8%" class="text-center">ลำดับ</th>
                            <th>ประเด็นย่อย (Sub Criteria)</th>
                            <th width="15%" class="text-center">คะแนนเต็ม</th>
                            <th width="15%" class="text-center">จัดการ</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($sub_criteria[$m_cat['id']] as $sub): ?>
                        <tr>
                            <td class="text-center text-muted align-middle"><?php echo htmlspecialchars($m_cat['sort_order'] . '.' . $sub['sort_order']); ?></td>
                            <td class="align-middle">
                                <div class="font-weight-bold text-dark"><i class="fas fa-angle-right text-muted mr-1"></i> <?php echo htmlspecialchars($sub['title']); ?></div>
                                <?php if(!empty($sub['description'])): ?>
                                    <small class="text-muted"><?php echo htmlspecialchars($sub['description']); ?></small>
                                <?php endif; ?>
                            </td>
                            <td class="text-center align-middle font-weight-bold"><?php echo number_format($sub['max_score'], 2); ?></td>
                            <td class="text-center align-middle">
                                <button class="btn btn-outline-warning btn-sm btn-edit" 
                                        data-id="<?php echo $sub['id']; ?>" 
                                        data-parent="<?php echo $sub['parent_id']; ?>"
                                        data-title="<?php echo htmlspecialchars($sub['title']); ?>"
                                        data-desc="<?php echo htmlspecialchars($sub['description']); ?>"
                                        data-max="<?php echo $sub['max_score']; ?>"
                                        data-sort="<?php echo $sub['sort_order']; ?>"
                                        data-targetgroup="<?php echo $sub['target_group']; ?>"
                                        data-toggle="modal" data-target="#editModal" title="แก้ไขประเด็นย่อย">
                                    <i class="fas fa-edit"></i>
                                </button>
                                <button class="btn btn-outline-danger btn-sm btn-delete" data-id="<?php echo $sub['id']; ?>" title="ลบประเด็นย่อย">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <div class="p-4 text-center text-muted">
                    <i class="fas fa-info-circle mr-1"></i> ยังไม่มีประเด็นย่อยในหัวข้อนี้
                    <button class="btn btn-link btn-sm ml-2" data-toggle="modal" data-target="#addSubModal" onclick="$('#sub_parent_id').val('<?php echo $m_cat['id']; ?>'); $('#sub_target_group').val('<?php echo $m_cat['target_group']; ?>');">
                        คลิกที่นี่เพื่อเพิ่มประเด็นย่อย
                    </button>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>
