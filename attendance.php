<?php
/**
 * Plugin Name: SIR Style Attendance Pro (Width Optimized)
 * Description: 가로 사이즈를 모든 테마 컨텐츠 영역에 맞춰 최적화한 출석 시스템
 */

if (!defined('ABSPATH')) exit;

/**
 * 1. DB 테이블 생성
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
 * 2. 관리자 메뉴
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
 * 3. 출석 처리 (AJAX) - 숏코드와 배너 공용
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
 * 3. 논모달 플로팅 배너 및 네온 효과
 */
add_action('wp_footer', 'sir_attendance_neon_floating_banner');
function sir_attendance_neon_floating_banner() {
    if (!is_user_logged_in()) return;

    global $wpdb;
    $user_id = get_current_user_id();
    $table_name = $wpdb->prefix . 'attendance_logs';
    $today = date('Y-m-d');

    $is_today_done = $wpdb->get_var($wpdb->prepare("SELECT id FROM $table_name WHERE user_id = %d AND check_date = %s", $user_id, $today));
    
    // 출석 완료 시 배너를 출력하지 않음 (자동 사라짐 효과)
    if ($is_today_done) return;

    $total_days = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table_name WHERE user_id = %d", $user_id));
    $total_points = (int)$wpdb->get_var($wpdb->prepare("SELECT SUM(points + bonus_points) FROM $table_name WHERE user_id = %d", $user_id));
    ?>

    <div id="sir-atc-neon-banner" onclick="toggleAtcNonModal()">
        <div class="neon-icon">🔔</div>
        <div class="neon-text">오늘의 출석이 남았습니다!</div>
    </div>

    <div id="sir-atc-nonmodal-window">
        <div class="atc-window-header">
            <span>출석체크 현황</span>
            <span class="close-btn" onclick="toggleAtcNonModal()">&times;</span>
        </div>
        <div class="atc-window-body">
            <p>누적 출석: <strong><?php echo $total_days; ?>일</strong></p>
            <p>누적 포인트: <strong><?php echo number_format($total_points); ?>P</strong></p>
            <button id="sir-atc-action-btn" class="neon-action-btn">지금 출석하기</button>
        </div>
    </div>

    <style>
        /* 미출석 네온 배너 스타일 */
        #sir-atc-neon-banner {
            position: fixed; bottom: 30px; right: 30px; z-index: 9998;
            background: #222; color: #fff; padding: 12px 25px;
            border-radius: 50px; cursor: pointer; display: flex; align-items: center; gap: 10px;
            border: 2px solid #00d4ff;
            box-shadow: 0 0 10px #00d4ff, inset 0 0 5px #00d4ff;
            animation: neonPulse 1.5s infinite alternate;
        }
        @keyframes neonPulse {
            from { box-shadow: 0 0 10px #00d4ff, 0 0 20px #00d4ff; }
            to { box-shadow: 0 0 20px #ff00ff, 0 0 40px #ff00ff; border-color: #ff00ff; }
        }
        .neon-text { font-size: 14px; font-weight: bold; }

        /* 논모달 윈도우 스타일 (배경 어둡게 처리 없음) */
        #sir-atc-nonmodal-window {
            position: fixed; bottom: 90px; right: 30px; z-index: 9999;
            width: 280px; background: #fff; border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
            display: none; flex-direction: column; overflow: hidden;
            border: 1px solid #eee;
        }
        .atc-window-header { background: #f8f9fa; padding: 10px 15px; display: flex; justify-content: space-between; font-size: 13px; font-weight: bold; border-bottom: 1px solid #eee; }
        .close-btn { cursor: pointer; color: #aaa; font-size: 18px; }
        .atc-window-body { padding: 20px; text-align: left; }
        .atc-window-body p { margin: 5px 0; font-size: 14px; }
        .neon-action-btn {
            margin-top: 15px; width: 100%; padding: 10px; background: #4a6cf7;
            color: #fff; border: none; border-radius: 8px; font-weight: bold; cursor: pointer;
        }
    </style>

    <script>
        function toggleAtcNonModal() {
            const win = document.getElementById('sir-atc-nonmodal-window');
            win.style.display = (win.style.display === 'flex') ? 'none' : 'flex';
        }

        jQuery(document).ready(function($) {
            $('#sir-atc-action-btn').on('click', function() {
                var $btn = $(this);
                $btn.prop('disabled', true).text('처리 중...');
                $.post('<?php echo admin_url('admin-ajax.php'); ?>', {
                    action: 'process_attendance',
                    security: '<?php echo wp_create_nonce("sir_attendance_nonce"); ?>'
                }, function(res) {
                    if(res.success) {
                        alert(res.data);
                        // 출석 완료 후 배너와 창을 즉시 제거
                        $('#sir-atc-neon-banner, #sir-atc-nonmodal-window').fadeOut();
                        location.reload(); 
                    } else {
                        alert(res.data);
                        $btn.prop('disabled', false).text('지금 출석하기');
                    }
                });
            });
        });
    </script>
    <?php
}

/**
 * 4. 숏코드 [sir_attendance]
 */
add_shortcode('sir_attendance', 'sir_attendance_render_view');
function sir_attendance_render_view() {
    if (!is_user_logged_in()) return "<div class='wp-atc-login-msg'>로그인이 필요합니다.</div>";

    global $wpdb;
    $user_id = get_current_user_id();
    $table_name = $wpdb->prefix . 'attendance_logs';
    $today = date('Y-m-d');

    // 데이터 계산
    $total_days = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table_name WHERE user_id = %d", $user_id));
    $is_today_done = $wpdb->get_var($wpdb->prepare("SELECT id FROM $table_name WHERE user_id = %d AND check_date = %s", $user_id, $today));
    
    $cont_days = 0;
    $check_date = $is_today_done ? $today : date('Y-m-d', strtotime('-1 day'));
    while ($wpdb->get_var($wpdb->prepare("SELECT id FROM $table_name WHERE user_id = %d AND check_date = %s", $user_id, $check_date))) {
        $cont_days++;
        $check_date = date('Y-m-d', strtotime('-1 day', strtotime($check_date)));
    }

    $max_cont = max($cont_days, (int)get_user_meta($user_id, '_sir_atc_max_cont', true));
    update_user_meta($user_id, '_sir_atc_max_cont', $max_cont);

    $rank_name = '새싹';
    if (function_exists('mycred_get_users_rank')) {
        $rank_obj = mycred_get_users_rank($user_id);
        $rank_name = is_object($rank_obj) ? $rank_obj->title : $rank_obj;
    }
    $next_rank_days = 10 - ($total_days % 10);

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
            <h4 class="section-title">📅 나의 최근 출석 내역</h4>
            <div class="atc-history-table-wrapper">
                <table class="atc-modern-table">
                    <thead><tr><th>날짜</th><th>포인트</th><th>상태</th></tr></thead>
                    <tbody>
                        <?php 
                        $recent = $wpdb->get_results($wpdb->prepare("SELECT * FROM $table_name WHERE user_id = %d ORDER BY check_date DESC LIMIT 5", $user_id));
                        if($recent): foreach ($recent as $r) : ?>
                            <tr><td><?php echo $r->check_date; ?></td><td><?php echo (int)$r->points + (int)$r->bonus_points; ?>P</td><td>출석완료</td></tr>
                        <?php endforeach; else: ?>
                            <tr><td colspan="3" style="text-align:center; padding:1.5rem;">기록이 없습니다.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
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
                if(res.success) { location.reload(); } else { alert(res.data); $btn.prop('disabled', false).text('오늘의 출석체크 하기'); }
            });
        });
    });
    </script>

    <style>
        /* 가로 사이즈 최적화 테마 대응 CSS */
        #wp-atc-modern-wrapper, #wp-atc-modern-wrapper * { box-sizing: border-box; }
        #wp-atc-modern-wrapper { 
            width: 100%; 
            /* 가로폭 제한을 유연하게 조정 (테마 폭에 맞춤) */
            max-width: 100%; 
            margin: 20px auto; 
            padding: 0;
            overflow: hidden;
        }

        .atc-header-section { display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; margin-bottom: 25px; gap: 15px; }
        .atc-rank-badge-info { display: flex; align-items: center; gap: 15px; }
        .atc-rank-icon { font-size: 32px; background: #f8f9fa; width: 54px; height: 54px; display: flex; align-items: center; justify-content: center; border-radius: 50%; }
        .atc-rank-title { font-size: 20px; margin: 0; font-weight: bold; }
        .atc-rank-subtitle { margin: 0; color: #888; font-size: 13px; }
        .atc-next-rank-msg { font-size: 14px; font-weight: 600; }

        .atc-today-status { text-align: center; margin-bottom: 25px; }
        .status-done { font-size: 16px; font-weight: 600; color: #444; }
        .status-btn-active { 
            background: #4a6cf7; color: #fff; border: none; padding: 12px 30px; border-radius: 8px; 
            font-weight: bold; cursor: pointer; transition: 0.2s;
        }

        /* 카드 그리드: 가로폭에 따라 자동 배치 */
        .atc-cards-container { 
            display: grid; 
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); 
            gap: 20px; 
            margin-bottom: 30px; 
        }
        .atc-stat-card { padding: 20px; border-radius: 15px; display: flex; justify-content: space-between; align-items: flex-start; }
        .card-blue { background-color: #eaf2ff; } 
        .card-purple { background-color: #f6f0ff; } 
        .card-yellow { background-color: #fff9e6; }
        .card-value { font-size: 28px; font-weight: 800; margin: 5px 0; line-height: 1.2; }
        .card-label { font-size: 13px; font-weight: bold; opacity: 0.7; }
        .card-subtext { font-size: 12px; opacity: 0.6; margin: 5px 0 0 0; }
        .card-icon-box { background: rgba(255,255,255,0.4); width: 32px; height: 32px; border-radius: 8px; display: flex; align-items: center; justify-content: center; }

        /* 섹션 공통 */
        .atc-history-section, .atc-ranking-section { background: #fff; border-radius: 15px; padding: 20px; border: 1px solid #eee; margin-bottom: 25px; width: 100%; }
        .section-title { font-size: 16px; font-weight: bold; margin: 0 0 15px 0; padding-left: 10px; border-left: 4px solid #4a6cf7; }
        
        /* 표 가로 사이즈 고정 및 스크롤 방지 */
        .atc-history-table-wrapper { width: 100%; overflow-x: auto; }
        .atc-modern-table { width: 100%; border-collapse: collapse; }
        .atc-modern-table th { text-align: left; padding: 10px; border-bottom: 2px solid #f1f1f1; color: #999; font-size: 12px; }
        .atc-modern-table td { padding: 12px 10px; border-bottom: 1px solid #f9f9f9; font-size: 14px; }

        /* 랭킹 리스트 가로 전체 사용 */
        .atc-rank-row { display: flex; align-items: center; padding: 10px 0; border-bottom: 1px solid #f9f9f9; width: 100%; }
        .atc-rank-num { width: 24px; height: 24px; flex-shrink: 0; display: flex; align-items: center; justify-content: center; border-radius: 50%; background: #eee; font-size: 11px; margin-right: 12px; font-weight: bold; }
        .top-rank { background: #ffd700; color: #fff; }
        .atc-rank-user { display: flex; align-items: center; gap: 10px; flex: 1; overflow: hidden; }
        .atc-rank-display { font-weight: bold; font-size: 14px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .atc-rank-tag { font-size: 10px; background: #f0f0f0; padding: 2px 6px; border-radius: 4px; }

        @media screen and (max-width: 600px) {
            .atc-header-section { flex-direction: column; text-align: center; justify-content: center; }
            .atc-rank-badge-info { flex-direction: column; }
            .atc-cards-container { grid-template-columns: 1fr; }
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
    delete_option('sir_atc_base_points');
    delete_option('sir_atc_bonus_points');
}
