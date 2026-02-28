<?php
/**
 * Plugin Name: SIR Style Attendance Pro (Complete Edition)
 * Description: 관리자 관리, 네온 플로팅 배너, 현대적인 숏코드 레이아웃이 모두 통합된 최종 버전
 */

if (!defined('ABSPATH')) exit;

/**
 * 1. DB 테이블 생성 및 초기화
 */
register_activation_hook(__FILE__, 'sir_attendance_setup_table');
function sir_attendance_setup_table() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'attendance_logs';
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE IF NOT EXISTS $table_name (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        user_id bigint(20) NOT NULL,
        check_date date NOT NULL,
        points int(10) DEFAULT 0,
        bonus_points int(10) DEFAULT 0,
        PRIMARY KEY (id),
        UNIQUE KEY user_date (user_id, check_date)
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);

    add_option('sir_atc_base_points', 10);
    add_option('sir_atc_bonus_points', 5);
}

/**
 * 2. 관리자 메뉴 및 데이터 처리 (백업/복구/초기화)
 */
add_action('admin_menu', 'sir_attendance_admin_menu');
function sir_attendance_admin_menu() {
    add_menu_page('출석 시스템', '출석 시스템', 'manage_options', 'sir-attendance-monitor', 'sir_attendance_monitor_page', 'dashicons-calendar-check', 25);
    add_submenu_page('sir-attendance-monitor', '기록 목록', '기록 목록', 'manage_options', 'sir-attendance-monitor', 'sir_attendance_monitor_page');
    add_submenu_page('sir-attendance-monitor', '설정 및 데이터 관리', '설정 및 데이터 관리', 'manage_options', 'sir-attendance-settings', 'sir_attendance_settings_page');
}

// 백업 기능
add_action('admin_init', 'sir_attendance_handle_backup');
function sir_attendance_handle_backup() {
    if (isset($_GET['page']) && $_GET['page'] === 'sir-attendance-settings' && isset($_GET['action']) && $_GET['action'] === 'backup') {
        if (!current_user_can('manage_options')) return;
        global $wpdb;
        $results = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}attendance_logs", ARRAY_A);
        header('Content-Type: application/json');
        header('Content-Disposition: attachment; filename="attendance_backup_'.date('Ymd_His').'.json"');
        echo json_encode($results);
        exit;
    }
}

function sir_attendance_monitor_page() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'attendance_logs';
    $logs = $wpdb->get_results("SELECT * FROM $table_name ORDER BY check_date DESC LIMIT 50");
    echo '<div class="wrap"><h1>📅 출석 기록 모니터링</h1><table class="wp-list-table widefat fixed striped"><thead><tr><th>사용자</th><th>날짜</th><th>포인트 합계</th></tr></thead><tbody>';
    if($logs) {
        foreach ($logs as $log) {
            $u = get_userdata($log->user_id);
            $total = (int)$log->points + (int)$log->bonus_points;
            echo "<tr><td>".($u ? $u->display_name : '탈퇴회원')."</td><td>{$log->check_date}</td><td><strong>{$total}P</strong></td></tr>";
        }
    } else { echo '<tr><td colspan="3" style="text-align:center;">데이터가 없습니다.</td></tr>'; }
    echo '</tbody></table></div>';
}

function sir_attendance_settings_page() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'attendance_logs';

    if (isset($_POST['save_sir_settings']) && check_admin_referer('sir_atc_settings_action', 'sir_atc_nonce')) {
        update_option('sir_atc_base_points', intval($_POST['base_points']));
        update_option('sir_atc_bonus_points', intval($_POST['bonus_points']));
        echo '<div class="updated"><p>설정이 저장되었습니다.</p></div>';
    }

    if (isset($_POST['restore_attendance_data']) && check_admin_referer('sir_atc_restore_action', 'sir_atc_restore_nonce')) {
        if (!empty($_FILES['backup_file']['tmp_name'])) {
            $data = json_decode(file_get_contents($_FILES['backup_file']['tmp_name']), true);
            if (is_array($data)) {
                $wpdb->query("TRUNCATE TABLE $table_name");
                foreach ($data as $row) {
                    $wpdb->insert($table_name, ['user_id' => $row['user_id'], 'check_date' => $row['check_date'], 'points' => $row['points'], 'bonus_points' => $row['bonus_points']]);
                }
                echo '<div class="updated"><p>데이터 복구가 완료되었습니다.</p></div>';
            }
        }
    }

    if (isset($_POST['reset_attendance_all']) && check_admin_referer('sir_atc_reset_action', 'sir_atc_reset_nonce')) {
        $wpdb->query("TRUNCATE TABLE $table_name");
        echo '<div class="notice notice-warning"><p>모든 데이터가 초기화되었습니다.</p></div>';
    }

    $base = get_option('sir_atc_base_points', 10);
    $bonus = get_option('sir_atc_bonus_points', 5);
    ?>
    <div class="wrap">
        <h1>⚙️ 출석 설정 및 데이터 관리</h1>
        <div class="card" style="max-width:800px; padding:20px; margin-bottom:20px; border-radius:12px;">
            <h2>포인트 설정</h2>
            <form method="post">
                <?php wp_nonce_field('sir_atc_settings_action', 'sir_atc_nonce'); ?>
                <table class="form-table">
                    <tr><th>기본 포인트</th><td><input type="number" name="base_points" value="<?php echo $base; ?>"> P</td></tr>
                    <tr><th>연속 보너스</th><td><input type="number" name="bonus_points" value="<?php echo $bonus; ?>"> P</td></tr>
                </table>
                <p><input type="submit" name="save_sir_settings" class="button button-primary" value="설정 저장"></p>
            </form>
        </div>
        <div style="display:flex; gap:20px; max-width:800px;">
            <div class="card" style="flex:1; padding:20px; border-radius:12px;">
                <h2>📦 백업 및 복구</h2>
                <a href="<?php echo admin_url('admin.php?page=sir-attendance-settings&action=backup'); ?>" class="button button-secondary">백업 다운로드 (.json)</a>
                <form method="post" enctype="multipart/form-data" style="margin-top:15px;">
                    <?php wp_nonce_field('sir_atc_restore_action', 'sir_atc_restore_nonce'); ?>
                    <input type="file" name="backup_file" accept=".json" required><br><br>
                    <input type="submit" name="restore_attendance_data" class="button" value="복구하기" onclick="return confirm('기존 데이터가 모두 삭제됩니다. 계속하시겠습니까?');">
                </form>
            </div>
            <div class="card" style="flex:1; padding:20px; border:1px solid #d63638; border-radius:12px;">
                <h2 style="color:#d63638;">⚠️ 데이터 초기화</h2>
                <p>시스템의 모든 출석 로그를 삭제합니다.</p>
                <form method="post">
                    <?php wp_nonce_field('sir_atc_reset_action', 'sir_atc_reset_nonce'); ?>
                    <input type="submit" name="reset_attendance_all" class="button button-link-delete" style="color:#d63638; text-decoration:none;" value="전체 초기화 수행" onclick="return confirm('정말로 모든 데이터를 삭제하시겠습니까?');">
                </form>
            </div>
        </div>
    </div>
    <?php
}

/**
 * 3. 출석 처리 AJAX (안정성 강화)
 */
add_action('wp_ajax_process_attendance', 'sir_ajax_process_attendance');
function sir_ajax_process_attendance() {
    check_ajax_referer('sir_attendance_nonce', 'security');
    if (!is_user_logged_in()) wp_send_json_error('로그인이 필요합니다.');

    global $wpdb;
    $user_id = get_current_user_id();
    $table_name = $wpdb->prefix . 'attendance_logs';
    $today = date('Y-m-d');
    $yesterday = date('Y-m-d', strtotime('-1 day'));

    $already = $wpdb->get_var($wpdb->prepare("SELECT id FROM $table_name WHERE user_id = %d AND check_date = %s", $user_id, $today));
    if ($already) wp_send_json_error('오늘은 이미 출석하셨습니다.');

    $is_continuous = $wpdb->get_var($wpdb->prepare("SELECT id FROM $table_name WHERE user_id = %d AND check_date = %s", $user_id, $yesterday));
    $base_p = (int)get_option('sir_atc_base_points', 10);
    $bonus_p = $is_continuous ? (int)get_option('sir_atc_bonus_points', 5) : 0;
    $total_p = $base_p + $bonus_p;

    $inserted = $wpdb->insert($table_name, ['user_id' => $user_id, 'check_date' => $today, 'points' => $base_p, 'bonus_points' => $bonus_p], ['%d', '%s', '%d', '%d']);

    if ($inserted) {
        if (function_exists('mycred_add')) mycred_add('attendance_check', $user_id, $total_p, '출석 보상', '', '', 'mycred_default');
        wp_send_json_success("출석 완료! {$total_p}P가 적립되었습니다.");
    } else { wp_send_json_error('저장 중 오류가 발생했습니다.'); }
}

/**
 * 4. 무지개 네온 플로팅 배너 및 모달
 */
add_action('wp_footer', 'sir_attendance_floating_neon_banner');
function sir_attendance_floating_neon_banner() {
    if (!is_user_logged_in()) return;
    global $wpdb; $user_id = get_current_user_id(); $table_name = $wpdb->prefix . 'attendance_logs'; $today = date('Y-m-d');
    if ($wpdb->get_var($wpdb->prepare("SELECT id FROM $table_name WHERE user_id = %d AND check_date = %s", $user_id, $today))) return;

    $total_days = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table_name WHERE user_id = %d", $user_id));
    $total_points = (int)$wpdb->get_var($wpdb->prepare("SELECT SUM(points + bonus_points) FROM $table_name WHERE user_id = %d", $user_id));
    ?>
    <div id="sir-atc-neon-btn" onclick="toggleAtcNonModal()">
        <div class="rainbow-border"></div>
        <div class="inner-icon-box"><span class="dashicons dashicons-yes-alt animated-check"></span></div>
    </div>

    <div id="sir-atc-nonmodal-window">
        <div class="atc-modal-top-accent"></div>
        <div class="atc-window-header">
            <div class="header-left"><span class="header-icon">🎁</span><span class="header-title">출석 보상 대기 중</span></div>
            <span class="close-btn" onclick="toggleAtcNonModal()">&times;</span>
        </div>
        <div class="atc-window-body">
            <div class="atc-mini-dashboard">
                <div class="mini-stat"><span class="mini-label">내 출석</span><span class="mini-value"><?php echo $total_days; ?>일</span></div>
                <div class="mini-stat"><span class="mini-label">총 포인트</span><span class="mini-value"><?php echo number_format($total_points); ?>P</span></div>
            </div>
            <button id="sir-atc-neon-action-btn" class="modern-go-btn">지금 바로 출석하기</button>
        </div>
    </div>

    <style>
        #sir-atc-neon-btn { position: fixed; bottom: 30px; right: 30px; z-index: 9998; width: 58px; height: 58px; border-radius: 50%; cursor: pointer; display: flex; align-items: center; justify-content: center; background: rgba(255, 255, 255, 0.8); backdrop-filter: blur(8px); box-shadow: 0 8px 32px rgba(0,0,0,0.1); animation: floatingAction 3s ease-in-out infinite; }
        .rainbow-border { position: absolute; top: -2px; left: -2px; right: -2px; bottom: -2px; border-radius: 50%; background: linear-gradient(45deg, #ff00ff, #00ffff, #ffff00, #00ff00, #ff00ff); background-size: 400% 400%; -webkit-mask: linear-gradient(#fff 0 0) content-box, linear-gradient(#fff 0 0); -webkit-mask-composite: xor; mask-composite: exclude; animation: rainbowShift 5s linear infinite; }
        .animated-check { font-size: 34px; width: 34px; height: 34px; background: linear-gradient(135deg, #6e8efb, #a777e3); -webkit-background-clip: text; -webkit-text-fill-color: transparent; }
        #sir-atc-nonmodal-window { position: fixed; bottom: 105px; right: 30px; z-index: 9999; width: 280px; background: #fff; border-radius: 24px; box-shadow: 0 20px 60px rgba(0,0,0,0.15); display: none; flex-direction: column; border: 1px solid rgba(0,0,0,0.05); overflow: hidden; animation: sirFadeUp 0.4s ease; }
        .atc-modal-top-accent { height: 6px; background: linear-gradient(to right, #4a6cf7, #a29bfe); }
        .atc-window-header { padding: 18px 20px 10px; display: flex; justify-content: space-between; align-items: center; }
        .header-title { font-weight: 700; color: #2d3436; }
        .close-btn { cursor: pointer; color: #b2bec3; font-size: 22px; }
        .atc-window-body { padding: 0 20px 20px; }
        .atc-mini-dashboard { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin: 15px 0; }
        .mini-stat { background: #f8faff; padding: 12px; border-radius: 16px; text-align: center; }
        .mini-label { display: block; font-size: 11px; color: #636e72; }
        .mini-value { font-weight: 800; font-size: 16px; color: #4a6cf7; }
        .modern-go-btn { width: 100%; padding: 14px; background: linear-gradient(135deg, #4a6cf7, #6c5ce7); color: #fff; border: none; border-radius: 14px; font-weight: 700; cursor: pointer; transition: 0.3s; }
        @keyframes rainbowShift { 0% { background-position: 0% 50%; } 100% { background-position: 400% 50%; } }
        @keyframes floatingAction { 0%, 100% { transform: translateY(0); } 50% { transform: translateY(-8px); } }
        @keyframes sirFadeUp { from { opacity: 0; transform: translateY(20px); } to { opacity: 1; transform: translateY(0); } }
    </style>
    <script>
        function toggleAtcNonModal() { const win = document.getElementById('sir-atc-nonmodal-window'); win.style.display = (win.style.display === 'flex') ? 'none' : 'flex'; }
        jQuery(document).ready(function($) {
            $(document).on('click', '#sir-atc-neon-action-btn', function() {
                var $btn = $(this);
                if($btn.hasClass('processing')) return;
                $btn.addClass('processing').prop('disabled', true).text('처리 중...');
                $.post('<?php echo admin_url('admin-ajax.php'); ?>', { action: 'process_attendance', security: '<?php echo wp_create_nonce("sir_attendance_nonce"); ?>' }, function(res) {
                    if(res.success) { alert(res.data); location.reload(); }
                    else { alert(res.data); $btn.removeClass('processing').prop('disabled', false).text('지금 바로 출석하기'); }
                });
            });
        });
    </script>
    <?php
}

/**
 * 5. 숏코드 [sir_attendance] (현대적 대시보드 레이아웃 CSS 포함)
 */
add_shortcode('sir_attendance', 'sir_attendance_render_view');
function sir_attendance_render_view() {
    if (!is_user_logged_in()) return "<div style='padding:40px; text-align:center; background:#f8faff; border-radius:20px;'>로그인이 필요합니다.</div>";
    
    global $wpdb; $user_id = get_current_user_id(); $table_name = $wpdb->prefix . 'attendance_logs'; $today = date('Y-m-d');
    $total_days = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table_name WHERE user_id = %d", $user_id));
    $is_today_done = $wpdb->get_var($wpdb->prepare("SELECT id FROM $table_name WHERE user_id = %d AND check_date = %s", $user_id, $today));
    
    // 연속 출석 계산
    $cont_days = 0; $check_date = $is_today_done ? $today : date('Y-m-d', strtotime('-1 day'));
    while ($wpdb->get_var($wpdb->prepare("SELECT id FROM $table_name WHERE user_id = %d AND check_date = %s", $user_id, $check_date))) { 
        $cont_days++; $check_date = date('Y-m-d', strtotime('-1 day', strtotime($check_date))); 
    }
    
    $rank_name = '새싹'; if (function_exists('mycred_get_users_rank')) { $rank_obj = mycred_get_users_rank($user_id); $rank_name = is_object($rank_obj) ? $rank_obj->title : $rank_obj; }
    $rankings = $wpdb->get_results("SELECT user_id, COUNT(*) as cnt FROM $table_name GROUP BY user_id ORDER BY cnt DESC LIMIT 10");

    ob_start(); ?>
    <div id="wp-atc-modern-wrapper">
        <div class="atc-header-card">
            <div class="atc-user-info">
                <div class="atc-avatar"><?php echo get_avatar($user_id, 64); ?></div>
                <div class="atc-user-text">
                    <h2>안녕하세요, <?php echo esc_html(wp_get_current_user()->display_name); ?>님!</h2>
                    <p class="atc-rank-display-line">
                    오늘도 활기찬 하루 되세요.<br>
                    나의 등급: <span class="rank-tag-main"><?php echo $rank_name; ?></span>
                    </p>
                </div>
            </div>
            <div class="atc-action-area">
                <?php if ($is_today_done) : ?>
                    <div class="status-badge done">오늘 출석 완료</div>
                <?php else : ?>
                    <button id="wp-atc-main-btn" class="status-btn-active">오늘의 출석체크 하기</button>
                <?php endif; ?>
            </div>
        </div>

        <div class="atc-grid-stats">
            <div class="stat-card blue">
                <span class="label">연속 출석</span>
                <span class="value"><?php echo $cont_days; ?>일</span>
                <div class="stat-icon"><span class="dashicons dashicons-calendar-alt"></span></div>
            </div>
            <div class="stat-card purple">
                <span class="label">누적 출석</span>
                <span class="value"><?php echo $total_days; ?>일</span>
                <div class="stat-icon"><span class="dashicons dashicons-chart-line"></span></div>
            </div>
            <div class="stat-card orange">
                <span class="label">보너스 등급</span>
                <span class="value"><?php echo $rank_name; ?></span>
                <div class="stat-icon"><span class="dashicons dashicons-awards"></span></div>
            </div>
        </div>

        <div class="atc-bottom-grid">
            <div class="atc-panel">
                <h3>📅 나의 최근 출석 내역</h3>
                <table class="atc-table">
                    <thead>
                        <tr>
                            <th>날짜</th>
                            <th>포인트</th>
                            <th>보너스</th><th>상태</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $recent = $wpdb->get_results($wpdb->prepare("SELECT * FROM $table_name WHERE user_id = %d ORDER BY check_date DESC LIMIT 5", $user_id));
                        if($recent): foreach ($recent as $r) : ?>
                            <tr>
                                <td><?php echo $r->check_date; ?></td>
                                <td><?php echo (int)$r->points; ?>P</td> <td class="bonus-cell"><?php echo (int)$r->bonus_points; ?>P</td> <td><span class="status-tag">완료</span></td>
                            </tr>
                        <?php endforeach; else: ?>
                            <tr><td colspan="4" style="text-align:center; padding:1.5rem;">기록이 없습니다.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <div class="atc-panel">
                <h3>🏆 출석 랭킹 (TOP 10)</h3>
                <div class="atc-rank-list">
                    <?php foreach ($rankings as $i => $row) : $u = get_userdata($row->user_id); if (!$u) continue; $u_rank = 'NEWBIE'; if(function_exists('mycred_get_users_rank')) {$r_obj = mycred_get_users_rank($row->user_id); $u_rank = is_object($r_obj) ? $r_obj->title : $r_obj;} ?>
                        <div class="atc-rank-item">
                            <span class="rank-num <?php echo ($i<3)?'top':''; ?>"><?php echo $i+1; ?></span>
                            <span class="rank-name"><?php echo esc_html($u->display_name); ?></span>
                            <span class="rank-user-tag"><?php echo esc_html($u_rank); ?></span>
                            <span class="rank-count"><strong><?php echo (int)$row->cnt; ?></strong>일</span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>

    <style>
        /* 상단 본인 등급 강조 디자인 */
        .atc-rank-display-line { margin: 8px 0 0; color: #666; line-height: 1.6; }
        .rank-tag-main { font-weight: bold; color: #4a6cf7; }

        /* 랭킹 리스트 내 회원등급 회색 박스 디자인 */
        .rank-user-tag { 
            background: #f0f0f0;  /* 회색 박스 배경 */
            color: #666;          /* 글자 색 */
            font-size: 10px;      /* 작은 글씨 */
            padding: 2px 8px;     /* 박스 안쪽 여백 */
            border-radius: 4px;   /* 둥근 모서리 */
            margin-right: 10px; /* [수정] 출석 횟수(1일)와의 간격을 확보 */
            font-weight: normal; 
            margin-left: 5px;     /* 이름과의 간격 */
            display: inline-block;  /* 간격 적용을 위해 추가 */
        }

        /* 테이블 헤더 폭 조정 */
        .atc-modern-table th, .atc-modern-table td {
            padding: 12px 10px;
            text-align: center; /* 텍스트 중앙 정렬로 가독성 확보 */
        }

        /* 보너스 포인트 숫자 강조 */
        .bonus-cell {
            color: #a777e3; /* 보너스 카드 색상 계열인 보라색 적용 */
            font-weight: bold;
        }

        /* 완료 태그 스타일 (선택 사항) */
        .status-tag {
            background: #e7f9ed;
            color: #2ecc71;
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 11px;
        }

        /* 테이블 래퍼 유동적 대응 */
        .atc-history-table-wrapper {
            overflow-x: auto; /* 화면이 작아지면 가로 스크롤 허용 */
        }

        /* 랭킹 숫자 일수 오른쪽 정렬 유지 */
        .rank-count { margin-left: auto; color: #4a6cf7; font-weight: bold; }

        #wp-atc-modern-wrapper { width:100%; font-family:'Pretendard','Malgun Gothic',sans-serif; color:#333; }
        /* 헤더 카드 */
        .atc-header-card { background:#fff; border-radius:20px; padding:30px; display:flex; justify-content:space-between; align-items:center; box-shadow:0 10px 30px rgba(0,0,0,0.05); margin-bottom:25px; flex-wrap:wrap; gap:20px; }
        .atc-user-info { display:flex; align-items:center; gap:20px; }
        .atc-avatar img { border-radius:50%; border:3px solid #f0f0f0; }
        .atc-user-text h2 { margin:0; font-size:20px; }
        .atc-user-text p { margin:5px 0 0; color:#666; }
        .status-btn-active { background:linear-gradient(135deg,#4a6cf7,#6c5ce7); color:#fff; border:none; padding:15px 30px; border-radius:12px; font-weight:bold; cursor:pointer; transition:0.3s; }
        .status-badge.done { background:#e7f9ed; color:#2ecc71; padding:12px 25px; border-radius:12px; font-weight:bold; }
        /* 스탯 그리드 */
        .atc-grid-stats { display:grid; grid-template-columns:repeat(auto-fit, minmax(200px, 1fr)); gap:20px; margin-bottom:25px; }
        .stat-card { padding:25px 30px; border-radius:20px; position:relative; overflow:hidden; color:#fff; }
        .stat-card.blue { background:linear-gradient(135deg,#6e8efb,#a777e3); }
        .stat-card.purple { background:linear-gradient(135deg,#9d50bb,#6e48aa); }
        .stat-card.orange { background:linear-gradient(135deg,#f2994a,#f2c94c); }
        .stat-card .label { font-size:14px; opacity:0.9; margin-left: 15px; }
        .stat-card .value { display:block; font-size:28px; font-weight:800; margin-top:10px; margin-left: 15px;}
        .stat-icon { position:absolute; right: 15px; top: 15px; line-height: 1px z-index: 1; bottom:10px; opacity:0.2; font-size:80px; pointer-enents: none; }
        /* 하단 레이아웃 */
        .atc-bottom-grid { display:grid; grid-template-columns:1.5fr 1fr; gap:25px; }
        @media (max-width:768px) { .atc-bottom-grid { grid-template-columns:1fr; } }
        .atc-panel { background:#fff; border-radius:20px; padding:25px; box-shadow:0 10px 30px rgba(0,0,0,0.05); height: auto; min-height: 400px; display: flex; flex-direction: column; }
        .atc-panel h3 { font-size:16px; margin:0 0 20px 0; border-left:4px solid #4a6cf7; padding-left:12px; }
        .atc-table { width:100%; border-collapse:collapse; }
        .atc-table th { text-align:left; color:#999; font-size:12px; padding:10px; border-bottom:1px solid #eee; }
        .atc-table td { padding:15px 10px; border-bottom:1px solid #f9f9f9; }
        .atc-rank-item { display:flex; align-items:center; padding:12px 0; border-bottom:1px solid #f9f9f9; }
        .atc-rank-list { flex: 1; overflow-y: visible;}
        .rank-num { width:24px; height:24px; border-radius:50%; background:#eee; display:flex; align-items:center; justify-content:center; font-size:11px; font-weight:bold; margin-right:12px; }
        .rank-num.top { background:#ffd700; color:#fff; }
        .rank-name { flex:1; font-weight:600; }
        .rank-count { color:#4a6cf7; font-weight:bold; }
    </style>
    <script>
        jQuery(document).ready(function($) {
            $('#wp-atc-main-btn').on('click', function() {
                var $btn = $(this);
                $btn.prop('disabled', true).text('처리 중...');
                $.post('<?php echo admin_url('admin-ajax.php'); ?>', { action: 'process_attendance', security: '<?php echo wp_create_nonce("sir_attendance_nonce"); ?>' }, function(res) {
                    if(res.success) { alert(res.data); location.reload(); }
                    else { alert(res.data); $btn.prop('disabled', false).text('오늘의 출석체크 하기'); }
                });
            });
        });
    </script>
    <?php
    return ob_get_clean();
}

/**
 * 6. 플러그인 삭제 시 정리
 */
register_uninstall_hook(__FILE__, 'sir_attendance_cleanup');
function sir_attendance_cleanup() {
    global $wpdb;
    $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}attendance_logs");
}
