<?php
/**
 * Plugin Name: SIR Style Attendance Pro (Modern UI + Ranking)
 * Description: 이미지 레이아웃 기반의 현대적인 출석 시스템 (랭킹 복구 버전)
 */

if (!defined('ABSPATH')) exit;

/**
 * 1. DB 테이블 생성 (bonus_points 컬럼 포함)
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
 * 2. 관리자 메뉴 (기록 목록 및 설정)
 */
add_action('admin_menu', 'sir_attendance_admin_menu');
function sir_attendance_admin_menu() {
    add_menu_page('출석 시스템', '출석 시스템', 'manage_options', 'sir-attendance-monitor', 'sir_attendance_monitor_page', 'dashicons-calendar-check', 25);
    add_submenu_page('sir-attendance-monitor', '기록 목록', '기록 목록', 'manage_options', 'sir-attendance-monitor', 'sir_attendance_monitor_page');
    add_submenu_page('sir-attendance-monitor', '설정 및 관리', '설정 및 관리', 'manage_options', 'sir-attendance-settings', 'sir_attendance_settings_page');
}

function sir_attendance_monitor_page() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'attendance_logs';
    $logs = $wpdb->get_results("SELECT * FROM $table_name ORDER BY check_date DESC LIMIT 50");
    echo '<div class="wrap"><h1>📅 출석 기록 모니터링</h1><table class="wp-list-table widefat fixed striped"><thead><tr><th>사용자</th><th>날짜</th><th>합계</th></tr></thead><tbody>';
    foreach ($logs as $log) {
        $u = get_userdata($log->user_id);
        $total = $log->points + $log->bonus_points;
        echo "<tr><td>".($u ? $u->display_name : '탈퇴회원')."</td><td>{$log->check_date}</td><td><strong>{$total}P</strong></td></tr>";
    }
    echo '</tbody></table></div>';
}

function sir_attendance_settings_page() {
    if (isset($_POST['save_sir_settings']) && check_admin_referer('sir_atc_settings_action', 'sir_atc_nonce')) {
        update_option('sir_atc_base_points', intval($_POST['base_points']));
        update_option('sir_atc_bonus_points', intval($_POST['bonus_points']));
        echo '<div class="updated"><p>설정이 저장되었습니다.</p></div>';
    }
    $base = get_option('sir_atc_base_points', 10);
    $bonus = get_option('sir_atc_bonus_points', 5);
    echo '<div class="wrap"><h1>⚙️ 출석 설정</h1><form method="post" class="card" style="max-width:500px; padding:20px;">';
    wp_nonce_field('sir_atc_settings_action', 'sir_atc_nonce');
    echo '<table class="form-table"><tr><th>기본 포인트</th><td><input type="number" name="base_points" value="'.$base.'"></td></tr><tr><th>연속 보너스</th><td><input type="number" name="bonus_points" value="'.$bonus.'"></td></tr></table><input type="submit" name="save_sir_settings" class="button button-primary" value="저장"></form></div>';
}

/**
 * 3. 출석 처리 (AJAX)
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

    if ($wpdb->get_var($wpdb->prepare("SELECT id FROM $table_name WHERE user_id = %d AND check_date = %s", $user_id, $today))) {
        wp_send_json_error('오늘은 이미 출석하셨습니다.');
    }

    $is_continuous = $wpdb->get_var($wpdb->prepare("SELECT id FROM $table_name WHERE user_id = %d AND check_date = %s", $user_id, $yesterday));
    $base_p = (int)get_option('sir_atc_base_points', 10);
    $bonus_p = $is_continuous ? (int)get_option('sir_atc_bonus_points', 5) : 0;
    $total_p = $base_p + $bonus_p;

    if (function_exists('mycred_add')) {
        mycred_add('attendance_check', $user_id, $total_p, '출석 보상', '', '', 'mycred_default');
    }

    $wpdb->insert($table_name, ['user_id' => $user_id, 'check_date' => $today, 'points' => $base_p, 'bonus_points' => $bonus_p]);
    wp_send_json_success("출석 완료! {$total_p}P 적립!");
}

/**
 * 4. 숏코드 [sir_attendance] (이미지 레이아웃 + 랭킹 복구)
 */
add_shortcode('sir_attendance', 'sir_attendance_render_view');
function sir_attendance_render_view() {
    if (!is_user_logged_in()) return "<div class='wp-atc-login-msg'>로그인이 필요합니다.</div>";

    global $wpdb;
    $user_id = get_current_user_id();
    $table_name = $wpdb->prefix . 'attendance_logs';
    $today = date('Y-m-d');

    // 통계 계산
    $total_days = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table_name WHERE user_id = %d", $user_id));
    $is_today_done = $wpdb->get_var($wpdb->prepare("SELECT id FROM $table_name WHERE user_id = %d AND check_date = %s", $user_id, $today));
    
    // 연속 출석 계산
    $cont_days = 0;
    $check_date = $is_today_done ? $today : date('Y-m-d', strtotime('-1 day'));
    while ($wpdb->get_var($wpdb->prepare("SELECT id FROM $table_name WHERE user_id = %d AND check_date = %s", $user_id, $check_date))) {
        $cont_days++;
        $check_date = date('Y-m-d', strtotime('-1 day', strtotime($check_date)));
    }

    // 최고 기록 업데이트
    $max_cont = max($cont_days, (int)get_user_meta($user_id, '_sir_atc_max_cont', true));
    update_user_meta($user_id, '_sir_atc_max_cont', $max_cont);

    // 등급 정보
    $rank_name = '새싹';
    if (function_exists('mycred_get_users_rank')) {
        $rank_obj = mycred_get_users_rank($user_id);
        $rank_name = is_object($rank_obj) ? $rank_obj->title : $rank_obj;
    }
    $next_rank_days = 10 - ($total_days % 10);

    // 랭킹 데이터 가져오기 (복구된 코드)
    $rankings = $wpdb->get_results("SELECT user_id, COUNT(*) as cnt FROM $table_name GROUP BY user_id ORDER BY cnt DESC LIMIT 10");

    ob_start(); ?>
    <div id="wp-atc-modern-wrapper">
        <div class="atc-header-section">
            <div class="atc-rank-badge-info">
                <div class="atc-rank-icon">🌱</div>
                <div class="atc-rank-text">
                    <h2 class="atc-rank-title"><?php echo esc_html($rank_name); ?></h2>
                    <p class="atc-rank-subtitle"><?php echo esc_html(wp_get_current_user()->display_name); ?>님의 출석 등급</p>
                </div>
            </div>
            <div class="atc-next-rank-msg">다음 등급까지 <strong><?php echo $next_rank_days; ?>일</strong> 남았어요!</div>
        </div>

        <div class="atc-today-status">
            <?php if ($is_today_done) : ?>
                <span class="status-done">✔️ 오늘 출석 완료! 내일 또 만나요 👋</span>
            <?php else : ?>
                <button id="wp-atc-action-trigger" class="status-btn-active">오늘의 출석체크 하기</button>
            <?php endif; ?>
        </div>

        <div class="atc-cards-container">
            <div class="atc-stat-card card-blue">
                <div class="card-content">
                    <span class="card-label">연속 출석</span>
                    <h3 class="card-value"><?php echo $cont_days; ?>일</h3>
                    <p class="card-subtext">최고 기록: <?php echo $max_cont; ?>일</p>
                </div>
                <div class="card-icon-box"><span class="dashicons dashicons-yes-alt"></span></div>
            </div>

            <div class="atc-stat-card card-purple">
                <div class="card-content">
                    <span class="card-label">누적 출석</span>
                    <h3 class="card-value"><?php echo $total_days; ?>일</h3>
                </div>
                <div class="card-icon-box"><span class="dashicons dashicons-calendar-alt"></span></div>
            </div>

            <div class="atc-stat-card card-yellow">
                <div class="card-content">
                    <span class="card-label">나의 등급</span>
                    <div class="card-rank-flex"><span class="small-rank-icon">🌱</span><h3 class="card-value"><?php echo esc_html($rank_name); ?></h3></div>
                    <p class="card-subtext"><?php echo $next_rank_days; ?>일 더 출석하면 다음 등급!</p>
                </div>
                <div class="card-icon-box"><span class="dashicons dashicons-star-filled"></span></div>
            </div>
        </div>

        <div class="atc-history-section">
                    <h4 class="section-title">최근 출석 내역</h4>
                    <div class="atc-history-table-wrapper">
                        <table class="atc-modern-table">
                            <thead><tr><th>날짜</th><th>포인트</th><th>상태</th></tr></thead>
                            <tbody>
                                <?php 
                                $recent = $wpdb->get_results($wpdb->prepare("SELECT * FROM $table_name WHERE user_id = %d ORDER BY check_date DESC LIMIT 5", $user_id));
                                foreach ($recent as $r) : ?>
                                    <tr><td><?php echo $r->check_date; ?></td><td><?php echo $r->points + $r->bonus_points; ?>P</td><td>출석완료</td></tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

        <div class="atc-ranking-section">
            <h4 class="section-title">🏆 출석 랭킹 TOP 10</h4>
            <div class="atc-rank-list">
                <?php foreach ($rankings as $i => $row) : 
                    $u = get_userdata($row->user_id);
                    if (!$u) continue;
                    $r_obj = function_exists('mycred_get_users_rank') ? mycred_get_users_rank($row->user_id) : '';
                    $r_title = is_object($r_obj) ? $r_obj->title : $r_obj;
                ?>
                    <div class="atc-rank-row">
                        <span class="atc-rank-num <?php echo ($i<3)?'top-rank':''; ?>"><?php echo $i+1; ?></span>
                        <div class="atc-rank-user">
                            <?php echo get_avatar($row->user_id, 32); ?>
                            <div class="atc-rank-names">
                                <span class="atc-rank-display"><?php echo esc_html($u->display_name); ?></span>
                                <?php if ($r_title) : ?><span class="atc-rank-tag"><?php echo esc_html($r_title); ?></span><?php endif; ?>
                            </div>
                        </div>
                        <span class="atc-rank-count"><strong><?php echo (int)$row->cnt; ?></strong>일</span>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <script>
    jQuery(document).ready(function($) {
        $('#wp-atc-action-trigger').on('click', function() {
            var $btn = $(this);
            $btn.prop('disabled', true).text('처리 중...');
            $.post('<?php echo admin_url('admin-ajax.php'); ?>', {
                action: 'process_attendance',
                security: '<?php echo wp_create_nonce("sir_attendance_nonce"); ?>'
            }, function(res) {
                if(res.success) { location.reload(); } else { alert(res.data); $btn.prop('disabled', false); }
            });
        });
    });
    </script>

    <style>
        #wp-atc-modern-wrapper { font-family: 'Pretendard', sans-serif; max-width: 1000px; margin: 20px auto; padding: 10px; }
        .atc-header-section { display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px; }
        .atc-rank-badge-info { display: flex; align-items: center; gap: 15px; }
        .atc-rank-icon { font-size: 40px; background: #f8f9fa; width: 60px; height: 60px; display: flex; align-items: center; justify-content: center; border-radius: 50%; }
        .atc-rank-title { font-size: 24px; margin: 0; font-weight: 800; }
        .atc-next-rank-msg { font-size: 16px; font-weight: 600; color: #444; }
        .atc-today-status { text-align: center; margin-bottom: 30px; }
        .status-done { font-size: 18px; font-weight: 600; color: #2d3436; }
        .status-btn-active { background: #4a6cf7; color: #fff; border: none; padding: 15px 40px; border-radius: 12px; font-weight: 700; cursor: pointer; }
        .atc-cards-container { display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px; margin-bottom: 40px; }
        .atc-stat-card { padding: 25px; border-radius: 20px; display: flex; justify-content: space-between; align-items: flex-start; }
        .card-blue { background-color: #eaf2ff; } .card-purple { background-color: #f6f0ff; } .card-yellow { background-color: #fff9e6; }
        .card-value { font-size: 32px; font-weight: 900; margin: 5px 0; }
        .card-icon-box { background: rgba(255,255,255,0.6); width: 40px; height: 40px; border-radius: 10px; display: flex; align-items: center; justify-content: center; color: #4a6cf7; }
        
        /* 랭킹 스타일 */
        .atc-ranking-section { background: #fff; border-radius: 20px; padding: 25px; border: 1px solid #eee; }
        .section-title { font-size: 18px; font-weight: 800; margin-bottom: 20px; padding-left: 10px; border-left: 4px solid #4a6cf7; }
        .atc-rank-row { display: flex; align-items: center; padding: 12px 0; border-bottom: 1px solid #f9f9f9; }
        .atc-rank-num { width: 28px; height: 28px; display: flex; align-items: center; justify-content: center; border-radius: 50%; background: #eee; font-size: 12px; margin-right: 15px; font-weight: bold; }
        .top-rank { background: #ffd700; color: #fff; }
        .atc-rank-user { display: flex; align-items: center; gap: 12px; flex: 1; }
        .atc-rank-display { font-weight: 700; font-size: 14px; }
        .atc-rank-tag { font-size: 10px; background: #f0f0f0; padding: 2px 6px; border-radius: 4px; margin-left: 5px; }
        @media screen and (max-width: 768px) { .atc-cards-container { grid-template-columns: 1fr; } .atc-header-section { flex-direction: column; text-align: center; gap: 10px; } }
    </style>
    <?php
    return ob_get_clean();
}

/**
 * 5. 플러그인 삭제 시 정리
 */
register_uninstall_hook(__FILE__, 'sir_attendance_cleanup');
function sir_attendance_cleanup() {
    global $wpdb;
    $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}attendance_logs");
    delete_option('sir_atc_base_points');
    delete_option('sir_atc_bonus_points');
}
