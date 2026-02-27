<?php
/**
 * Plugin Name: SIR Style Attendance for myCRED
 * Description: myCRED 연동 출석 체크 및 랭킹 시스템
 */

if (!defined('ABSPATH')) exit;

/**
 * 1. DB 테이블 생성 (플러그인 활성화 시)
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
        PRIMARY KEY (id),
        UNIQUE KEY user_date (user_id, check_date)
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
}

/**
 * 2. 관리자 메뉴 및 설정
 */
add_action('admin_menu', 'sir_attendance_admin_menu');
function sir_attendance_admin_menu() {
    add_menu_page('출석 시스템', '출석 시스템', 'manage_options', 'sir-attendance-monitor', 'sir_attendance_monitor_page', 'dashicons-calendar-check', 25);
    add_submenu_page('sir-attendance-monitor', '기록 목록', '기록 목록', 'manage_options', 'sir-attendance-monitor', 'sir_attendance_monitor_page');
    add_submenu_page('sir-attendance-monitor', '데이터 관리', '데이터 관리', 'manage_options', 'sir-attendance-settings', 'sir_attendance_settings_page');
}

// [관리자] 기록 목록 페이지
function sir_attendance_monitor_page() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'attendance_logs';
    $logs = $wpdb->get_results("SELECT * FROM $table_name ORDER BY check_date DESC LIMIT 50");
    echo '<div class="wrap"><h1>📅 출석 기록 모니터링</h1><table class="wp-list-table widefat fixed striped"><thead><tr><th>사용자</th><th>날짜</th><th>포인트</th></tr></thead><tbody>';
    foreach ($logs as $log) {
        $u = get_userdata($log->user_id);
        echo "<tr><td>".($u ? $u->display_name : '탈퇴회원')."</td><td>{$log->check_date}</td><td>+{$log->points}P</td></tr>";
    }
    echo '</tbody></table></div>';
}

// [관리자] 데이터 관리 페이지 (초기화)
function sir_attendance_settings_page() {
    global $wpdb;
    if (isset($_POST['sir_reset_confirm']) && check_admin_referer('sir_reset_action', 'sir_nonce')) {
        $wpdb->query("TRUNCATE TABLE {$wpdb->prefix}attendance_logs");
        echo '<div class="updated"><p>데이터가 초기화되었습니다.</p></div>';
    }
    echo '<div class="wrap"><h1>⚙️ 데이터 관리</h1><div class="card"><form method="post" onsubmit="return confirm(\'정말 초기화하시겠습니까?\');">';
    wp_nonce_field('sir_reset_action', 'sir_nonce');
    echo '<input type="submit" name="sir_reset_confirm" class="button button-primary" value="모든 출석 데이터 초기화" style="background:#d63638;"></form></div></div>';
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

    $already = $wpdb->get_var($wpdb->prepare("SELECT id FROM $table_name WHERE user_id = %d AND check_date = %s", $user_id, $today));
    if ($already) wp_send_json_error('오늘은 이미 출석하셨습니다.');

    $is_continuous = $wpdb->get_var($wpdb->prepare("SELECT id FROM $table_name WHERE user_id = %d AND check_date = %s", $user_id, $yesterday));
    $total_points = $is_continuous ? 15 : 10;

    if (function_exists('mycred_add')) {
        mycred_add('attendance_check', $user_id, $total_points, '출석 보상', '', '', 'mycred_default');
    }

    $wpdb->insert($table_name, ['user_id' => $user_id, 'check_date' => $today, 'points' => $total_points]);
    wp_send_json_success("출석 완료! {$total_points}P 적립!");
}

/**
 * 4. 숏코드 [sir_attendance]
 */
add_shortcode('sir_attendance', 'sir_attendance_render_view');
function sir_attendance_render_view() {
    if (!is_user_logged_in()) return "<div class='wp-atc-login-msg'>로그인 후 이용 가능합니다.</div>";

    global $wpdb;
    $user_id = get_current_user_id();
    $table_name = $wpdb->prefix . 'attendance_logs';
    $today = date('Y-m-d');

    // 데이터 가져오기 (이전 로직 동일)
    $total_count = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table_name WHERE user_id = %d", $user_id));
    $is_today_done = $wpdb->get_var($wpdb->prepare("SELECT id FROM $table_name WHERE user_id = %d AND check_date = %s", $user_id, $today));
    
    $cont_days = 0;
    $target_date = $is_today_done ? $today : date('Y-m-d', strtotime('-1 day'));
    for ($i = 0; $i < 365; $i++) {
        $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM $table_name WHERE user_id = %d AND check_date = %s", $user_id, $target_date));
        if ($exists) { $cont_days++; $target_date = date('Y-m-d', strtotime("-1 day", strtotime($target_date))); } 
        else { break; }
    }

    $rank_name = '일반회원';
    if (function_exists('mycred_get_users_rank')) {
        $rank_obj = mycred_get_users_rank($user_id);
        $rank_name = is_object($rank_obj) ? $rank_obj->title : $rank_obj;
    }

    $rankings = $wpdb->get_results("SELECT user_id, COUNT(*) as cnt FROM $table_name GROUP BY user_id ORDER BY cnt DESC LIMIT 10");

    ob_start(); ?>
    <div id="wp-atc-wrapper">
        <div class="wp-atc-card wp-atc-user-card">
            <div class="wp-atc-profile-section">
                <div class="wp-atc-avatar">
                    <?php echo get_avatar($user_id, 64); ?>
                </div>
                <div class="wp-atc-user-info">
                    <p class="wp-atc-user-welcome">안녕하세요, <strong><?php echo esc_html(wp_get_current_user()->display_name); ?></strong>님!</p>
                    <p class="wp-atc-sub-text">오늘도 잊지 말고 출석체크 하세요!</p>
                </div>
            </div>

            <?php if ($is_today_done) : ?>
                <div class="wp-atc-main-btn wp-atc-btn-done">✅ 오늘 출석 완료!</div>
            <?php else : ?>
                <button id="wp-atc-action-trigger" class="wp-atc-main-btn wp-atc-btn-active">오늘의 출석체크 하기</button>
            <?php endif; ?>

            <div class="wp-atc-stats-grid">
                <div class="wp-atc-stat-item wp-atc-bg-blue">
                    <span class="wp-atc-label">내 등급</span>
                    <strong class="wp-atc-value"><?php echo esc_html($rank_name); ?></strong>
                </div>
                <div class="wp-atc-stat-item wp-atc-bg-green">
                    <span class="wp-atc-label">연속 출석</span>
                    <strong class="wp-atc-value"><?php echo $cont_days; ?>일</strong>
                </div>
                <div class="wp-atc-stat-item wp-atc-bg-purple">
                    <span class="wp-atc-label">누적 출석</span>
                    <strong class="wp-atc-value"><?php echo $total_count; ?>일</strong>
                </div>
            </div>
        </div>

        <div class="wp-atc-card wp-atc-rank-card">
            <h3 class="wp-atc-rank-title">🏆 출석 랭킹 TOP 10</h3>
            <div class="wp-atc-rank-list">
                <?php foreach ($rankings as $i => $row) : 
                    $u = get_userdata($row->user_id);
                    if (!$u) continue;
                    $r_obj = function_exists('mycred_get_users_rank') ? mycred_get_users_rank($row->user_id) : '';
                    $r_title = is_object($r_obj) ? $r_obj->title : $r_obj;
                ?>
                    <div class="wp-atc-rank-row">
                        <span class="wp-atc-rank-num <?php echo ($i<3)?'wp-atc-top':''; ?>"><?php echo $i+1; ?></span>
                        <div class="wp-atc-rank-user">
                            <?php echo get_avatar($row->user_id, 32); ?>
                            <div class="wp-atc-rank-names">
                                <span class="wp-atc-rank-display"><?php echo esc_html($u->display_name); ?></span>
                                <?php if ($r_title) : ?><span class="wp-atc-rank-tag"><?php echo esc_html($r_title); ?></span><?php endif; ?>
                            </div>
                        </div>
                        <span class="wp-atc-rank-count"><strong><?php echo (int)$row->cnt; ?></strong>일</span>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <script>
    jQuery(document).ready(function($) {
        $('#wp-atc-action-trigger').on('click', function(e) {
            e.preventDefault();
            var $btn = $(this);
            if($btn.prop('disabled')) return;
            $btn.prop('disabled', true).text('처리 중...');
            $.post('<?php echo admin_url('admin-ajax.php'); ?>', {
                action: 'process_attendance',
                security: '<?php echo wp_create_nonce("sir_attendance_nonce"); ?>'
            }, function(res) {
                if(res.success) { alert(res.data); location.reload(); } 
                else { alert(res.data); $btn.prop('disabled', false).text('오늘의 출석체크 하기'); }
            });
        });
    });
    </script>

    <style>
        /*  */
        #wp-atc-wrapper { width: 100%; max-width: 800px; margin: 20px auto !important; padding: 0 !important; font-family: 'Malgun Gothic', sans-serif; line-height: 1.5; color: #333; }
        .wp-atc-card { background: #fff !important; border-radius: 15px !important; border: 1px solid #eee !important; padding: 25px !important; margin-bottom: 20px !important; box-shadow: 0 4px 12px rgba(0,0,0,0.05) !important; box-sizing: border-box !important; }
        
        .wp-atc-profile-section { display: flex !important; align-items: center !important; gap: 20px !important; margin-bottom: 25px !important; }
        .wp-atc-avatar img { border-radius: 50% !important; margin: 0 !important; }
        .wp-atc-user-welcome { margin: 0 !important; font-size: 18px !important; }
        .wp-atc-sub-text { margin: 0 !important; font-size: 14px !important; color: #888 !important; }

        .wp-atc-main-btn { display: block !important; width: 100% !important; padding: 18px !important; border-radius: 10px !important; border: none !important; font-size: 16px !important; font-weight: bold !important; text-align: center !important; cursor: pointer !important; transition: 0.2s !important; margin-bottom: 25px !important; text-decoration: none !important; box-sizing: border-box !important; }
        .wp-atc-btn-active { background: #4a6cf7 !important; color: #fff !important; }
        .wp-atc-btn-done { background: #f8f9ff !important; color: #4a6cf7 !important; cursor: default !important; border: 1px solid #ebf1ff !important; }

        .wp-atc-stats-grid { display: grid !important; grid-template-columns: repeat(3, 1fr) !important; gap: 15px !important; }
        .wp-atc-stat-item { padding: 15px !important; border-radius: 10px !important; text-align: center !important; }
        .wp-atc-label { display: block !important; font-size: 12px !important; margin-bottom: 5px !important; opacity: 0.8; }
        .wp-atc-value { display: block !important; font-size: 16px !important; font-weight: bold !important; }
        
        .wp-atc-bg-blue { background: #ebf1ff !important; color: #3552d1 !important; }
        .wp-atc-bg-green { background: #e7f9ed !important; color: #2ecc71 !important; }
        .wp-atc-bg-purple { background: #f3e8ff !important; color: #7b1fa2 !important; }

        .wp-atc-rank-title { font-size: 18px !important; margin: 0 0 20px 0 !important; padding: 0 0 0 10px !important; border-left: 4px solid #4a6cf7 !important; }
        .wp-atc-rank-row { display: flex !important; align-items: center !important; padding: 12px 0 !important; border-bottom: 1px solid #f9f9f9 !important; }
        .wp-atc-rank-num { width: 24px; height: 24px; display: flex !important; align-items: center !important; justify-content: center !important; border-radius: 50% !important; background: #eee !important; font-size: 11px !important; margin-right: 15px !important; flex-shrink: 0 !important; }
        .wp-atc-top { background: #ffd700 !important; color: #fff !important; font-weight: bold !important; }
        .wp-atc-rank-user { display: flex !important; align-items: center !important; gap: 10px !important; flex: 1 !important; }
        .wp-atc-rank-names { display: flex !important; flex-direction: column !important; }
        .wp-atc-rank-display { font-size: 14px !important; font-weight: bold !important; }
        .wp-atc-rank-tag { font-size: 10px !important; background: #eee !important; padding: 2px 5px !important; border-radius: 3px !important; width: fit-content !important; margin-top: 2px !important; }

        @media screen and (max-width: 600px) {
            .wp-atc-stats-grid { grid-template-columns: 1fr !important; }
            .wp-atc-profile-section { flex-direction: column !important; text-align: center !important; }
        }
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
}