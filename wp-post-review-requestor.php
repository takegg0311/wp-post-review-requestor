<?php
/**
 * Post Review Requestor
 *
 * Plugin Name: Post Review Requestor
 * Plugin URI:  https://github.com/takegg0311/wp-post-review-requestor
 * Description: 投稿・カスタム投稿・固定ページが「レビュー待ち」状態になった場合に通知を行い、ダッシュボード上にレビュー待ち投稿・ページ一覧を表示します。
 * Version:     0.0.1
 * Author:      takegg0311
 * Author URI:  https://github.com/takegg0311
 * License:     GPLv2 or later
 * License URI: http://www.gnu.org/licenses/old-licenses/gpl-2.0.html
 * Requires at least: 5.0
 * Requires PHP: 7.0
 *
 * This program is free software; you can redistribute it and/or modify it under the terms of the GNU
 * General Public License version 2, as published by the Free Software Foundation. You may NOT assume
 * that you can use any other version of the GPL.
 *
 * This program is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without
 * even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
 */


if ( ! defined( 'ABSPATH' ) ) {
	die( 'Invalid request.' );
}

// プラグインの定数定義
define( 'PRR_VERSION', '0.0.1' );
define( 'PRR_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'PRR_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/**
 * メインクラス
 */
class Post_Review_Requestor {
	
	private static $instance = null;
	
	/**
	 * シングルトンインスタンスを取得
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}
	
	/**
	 * コンストラクタ
	 */
	private function __construct() {
		$this->init_hooks();
	}
	
	/**
	 * フックの初期化
	 */
	private function init_hooks() {
		// 投稿ステータスの変更を監視
		add_action( 'transition_post_status', array( $this, 'handle_status_transition' ), 10, 3 );
		
		// ダッシュボードウィジェットを追加
		add_action( 'wp_dashboard_setup', array( $this, 'add_dashboard_widget' ) );
		
		// 管理メニューにページを追加
		add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
		
		// 管理画面のスタイルとスクリプトを読み込む
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
		
		// ユーザープロフィールページに通知設定を追加
		add_action( 'show_user_profile', array( $this, 'add_user_notification_setting' ) );
		add_action( 'edit_user_profile', array( $this, 'add_user_notification_setting' ) );
		
		// ユーザープロフィール設定を保存
		add_action( 'personal_options_update', array( $this, 'save_user_notification_setting' ) );
		add_action( 'edit_user_profile_update', array( $this, 'save_user_notification_setting' ) );
		
		// Cronジョブをスケジュール
		add_action( 'prr_send_queued_notifications', array( $this, 'send_queued_notifications' ) );
	}
	
	/**
	 * 投稿ステータスの変更を処理
	 */
	public function handle_status_transition( $new_status, $old_status, $post ) {
		// レビュー待ち（pending）状態になった場合のみ処理
		if ( $new_status !== 'pending' || $old_status === 'pending' ) {
			return;
		}
		
		// 投稿、カスタム投稿、固定ページのみ対象
		if ( ! in_array( $post->post_type, array( 'post', 'page' ) ) && ! $this->is_custom_post_type( $post->post_type ) ) {
			return;
		}
		
		// 通知を送信
		$this->send_notification( $post );
	}
	
	/**
	 * カスタム投稿タイプかどうかを判定
	 */
	private function is_custom_post_type( $post_type ) {
		$built_in_types = array( 'post', 'page', 'attachment', 'revision', 'nav_menu_item', 'custom_css', 'customize_changeset', 'oembed_cache', 'user_request', 'wp_block' );
		return ! in_array( $post_type, $built_in_types );
	}
	
	/**
	 * 通知を送信
	 */
	private function send_notification( $post ) {
		// 通知を受け取る設定をしている管理者・編集者を取得
		$recipients = $this->get_notification_recipients();
		
		if ( empty( $recipients ) ) {
			return;
		}
		
		// 投稿情報を取得
		$post_title = get_the_title( $post->ID );
		$post_type_obj = get_post_type_object( $post->post_type );
		$post_type_label = $post_type_obj ? $post_type_obj->labels->singular_name : $post->post_type;
		$post_author = get_userdata( $post->post_author );
		$author_name = $post_author ? $post_author->display_name : '不明';
		$edit_link = admin_url( 'post.php?post=' . $post->ID . '&action=edit' );
		
		// メール件名
		$subject = sprintf( 
			'[%s] レビュー依頼: %s',
			get_bloginfo( 'name' ),
			$post_title
		);
		
		// HTMLメールとして送信
		$html_message = sprintf(
			'<html><body>' .
			'<p>以下の%sがレビュー待ち状態になりました。</p>' .
			'<table style="border-collapse: collapse; width: 100%%; max-width: 600px;">' .
			'<tr><td style="padding: 8px; border: 1px solid #ddd; background-color: #f9f9f9; font-weight: bold;">タイトル</td><td style="padding: 8px; border: 1px solid #ddd;">%s</td></tr>' .
			'<tr><td style="padding: 8px; border: 1px solid #ddd; background-color: #f9f9f9; font-weight: bold;">投稿タイプ</td><td style="padding: 8px; border: 1px solid #ddd;">%s</td></tr>' .
			'<tr><td style="padding: 8px; border: 1px solid #ddd; background-color: #f9f9f9; font-weight: bold;">作成者</td><td style="padding: 8px; border: 1px solid #ddd;">%s</td></tr>' .
			'</table>' .
			'<p style="margin-top: 20px;"><a href="%s" style="display: inline-block; padding: 10px 20px; background-color: #0073aa; color: #fff; text-decoration: none; border-radius: 3px;">編集ページを開く</a></p>' .
			'<p style="margin-top: 20px;">レビューをお願いします。</p>' .
			'</body></html>',
			esc_html( $post_type_label ),
			esc_html( $post_title ),
			esc_html( $post_type_label ),
			esc_html( $author_name ),
			esc_url( $edit_link )
		);
		
		// 管理者メールアドレスを取得（From用）
		$admin_email = get_option( 'admin_email' );
		
		// メールヘッダー
		$headers = array(
			'Content-Type: text/html; charset=UTF-8',
			'From: ' . get_bloginfo( 'name' ) . ' <' . $admin_email . '>'
		);
		
		// 現在時刻を取得
		$current_hour = (int) current_time( 'H' );
		$current_minute = (int) current_time( 'i' );
		$current_time_minutes = $current_hour * 60 + $current_minute;
		
		// 各受信者にメール送信またはキューに保存
		foreach ( $recipients as $user_id => $recipient_email ) {
			$notification_time = $this->get_user_notification_time( $user_id );
			
			if ( $notification_time === null ) {
				// 時間帯制限なし（24時間受信）
				wp_mail( $recipient_email, $subject, $html_message, $headers );
			} else {
				$start_time_minutes = $notification_time['start_hour'] * 60 + $notification_time['start_minute'];
				$end_time_minutes = $notification_time['end_hour'] * 60 + $notification_time['end_minute'];
				
				// 時間帯内かチェック
				if ( $this->is_time_in_range( $current_time_minutes, $start_time_minutes, $end_time_minutes ) ) {
					// 時間帯内なので即座に送信
					wp_mail( $recipient_email, $subject, $html_message, $headers );
				} else {
					// 時間帯外なのでキューに保存
					$this->queue_notification( $user_id, $post->ID, $subject, $html_message, $headers );
				}
			}
		}
	}
	
	/**
	 * 通知を受け取る設定をしている管理者・編集者を取得
	 */
	private function get_notification_recipients() {
		$recipients = array();
		
		// 管理者・編集者権限を持つユーザーを取得
		$users = get_users( array(
			'role__in' => array( 'administrator', 'editor' ),
		) );
		
		foreach ( $users as $user ) {
			// 通知を受け取る設定をしているかチェック
			$receive_notifications = get_user_meta( $user->ID, 'prr_receive_notifications', true );
			
			if ( $receive_notifications === '1' || $receive_notifications === '' ) {
				// デフォルトでは有効（既存ユーザーへの後方互換性のため）
				$user_email = $user->user_email;
				if ( ! empty( $user_email ) ) {
					$recipients[ $user->ID ] = $user_email;
				}
			}
		}
		
		return $recipients;
	}
	
	/**
	 * ユーザーの通知受信時間帯を取得
	 */
	private function get_user_notification_time( $user_id ) {
		$time_enabled = get_user_meta( $user_id, 'prr_notification_time_enabled', true );
		
		if ( $time_enabled !== '1' ) {
			// 時間帯制限なし
			return null;
		}
		
		$start_hour = (int) get_user_meta( $user_id, 'prr_notification_start_hour', true );
		$end_hour = (int) get_user_meta( $user_id, 'prr_notification_end_hour', true );
		
		// デフォルト値
		if ( empty( $start_hour ) && $start_hour !== 0 ) {
			$start_hour = 9;
		}
		if ( empty( $end_hour ) && $end_hour !== 0 ) {
			$end_hour = 18;
		}
		
		// 分は常に0
		$start_minute = 0;
		$end_minute = 0;
		
		return array(
			'start_hour' => $start_hour,
			'start_minute' => $start_minute,
			'end_hour' => $end_hour,
			'end_minute' => $end_minute,
		);
	}
	
	/**
	 * 時刻が指定範囲内かチェック
	 */
	private function is_time_in_range( $current_minutes, $start_minutes, $end_minutes ) {
		if ( $start_minutes <= $end_minutes ) {
			// 通常の範囲（例：9:00-18:00）
			return $current_minutes >= $start_minutes && $current_minutes <= $end_minutes;
		} else {
			// 日をまたぐ範囲（例：22:00-6:00）
			return $current_minutes >= $start_minutes || $current_minutes <= $end_minutes;
		}
	}
	
	/**
	 * 通知をキューに保存
	 */
	private function queue_notification( $user_id, $post_id, $subject, $html_message, $headers ) {
		$queue = get_option( 'prr_notification_queue', array() );
		
		$queue[] = array(
			'user_id' => $user_id,
			'post_id' => $post_id,
			'subject' => $subject,
			'message' => $html_message,
			'headers' => $headers,
			'created_at' => current_time( 'mysql' ),
		);
		
		update_option( 'prr_notification_queue', $queue );
	}
	
	/**
	 * キューに保存された通知を送信
	 */
	public function send_queued_notifications() {
		$queue = get_option( 'prr_notification_queue', array() );
		
		if ( empty( $queue ) ) {
			return;
		}
		
		$current_hour = (int) current_time( 'H' );
		$current_minute = (int) current_time( 'i' );
		$current_time_minutes = $current_hour * 60 + $current_minute;
		
		$remaining_queue = array();
		
		foreach ( $queue as $notification ) {
			$user_id = $notification['user_id'];
			$notification_time = $this->get_user_notification_time( $user_id );
			
			if ( $notification_time === null ) {
				// 時間帯制限なしなので送信
				$user = get_userdata( $user_id );
				if ( $user && ! empty( $user->user_email ) ) {
					wp_mail( $user->user_email, $notification['subject'], $notification['message'], $notification['headers'] );
				}
			} else {
				$start_time_minutes = $notification_time['start_hour'] * 60 + $notification_time['start_minute'];
				$end_time_minutes = $notification_time['end_hour'] * 60 + $notification_time['end_minute'];
				
				if ( $this->is_time_in_range( $current_time_minutes, $start_time_minutes, $end_time_minutes ) ) {
					// 時間帯内なので送信
					$user = get_userdata( $user_id );
					if ( $user && ! empty( $user->user_email ) ) {
						wp_mail( $user->user_email, $notification['subject'], $notification['message'], $notification['headers'] );
					}
				} else {
					// まだ時間帯外なのでキューに残す
					$remaining_queue[] = $notification;
				}
			}
		}
		
		update_option( 'prr_notification_queue', $remaining_queue );
	}
	
	/**
	 * Cronジョブをスケジュール
	 */
	public function schedule_notification_cron() {
		if ( ! wp_next_scheduled( 'prr_send_queued_notifications' ) ) {
			wp_schedule_event( time(), 'hourly', 'prr_send_queued_notifications' );
		}
	}
	
	/**
	 * Cronジョブを削除
	 */
	public function unschedule_notification_cron() {
		$timestamp = wp_next_scheduled( 'prr_send_queued_notifications' );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, 'prr_send_queued_notifications' );
		}
	}
	
	/**
	 * ダッシュボードウィジェットを追加
	 */
	public function add_dashboard_widget() {
		// 管理者のみ表示
		if ( ! current_user_can( 'edit_posts' ) ) {
			return;
		}
		
		wp_add_dashboard_widget(
			'prr_pending_posts_widget',
			'レビュー待ち投稿・ページ',
			array( $this, 'render_dashboard_widget' )
		);
	}
	
	/**
	 * ダッシュボードウィジェットの内容を表示
	 */
	public function render_dashboard_widget() {
		$pending_posts = $this->get_pending_posts();
		
		if ( empty( $pending_posts ) ) {
			echo '<p>現在、レビュー待ちの投稿・ページはありません。</p>';
			return;
		}
		
		echo '<div class="prr-pending-posts-list">';
		echo '<table class="widefat">';
		echo '<thead><tr>';
		echo '<th>タイトル</th>';
		echo '<th>投稿タイプ</th>';
		echo '<th>作成者</th>';
		echo '<th>更新日</th>';
		echo '</tr></thead>';
		echo '<tbody>';
		
		foreach ( $pending_posts as $post ) {
			$post_type_obj = get_post_type_object( $post->post_type );
			$post_type_label = $post_type_obj ? $post_type_obj->labels->singular_name : $post->post_type;
			$post_author = get_userdata( $post->post_author );
			$author_name = $post_author ? $post_author->display_name : '不明';
			$edit_link = admin_url( 'post.php?post=' . $post->ID . '&action=edit' );
			$date = get_the_modified_date( 'Y年m月d日 H:i', $post->ID );
			
			echo '<tr>';
			echo '<td><a href="' . esc_url( $edit_link ) . '" target="_blank"><strong>' . esc_html( get_the_title( $post->ID ) ) . '</strong></a></td>';
			echo '<td>' . esc_html( $post_type_label ) . '</td>';
			echo '<td>' . esc_html( $author_name ) . '</td>';
			echo '<td>' . esc_html( $date ) . '</td>';
			echo '</tr>';
		}
		
		echo '</tbody>';
		echo '</table>';
		echo '<p style="margin-top: 10px;"><a href="' . esc_url( admin_url( 'admin.php?page=prr-pending-posts' ) ) . '" class="button">すべて表示</a></p>';
		echo '</div>';
	}
	
	/**
	 * 管理メニューにページを追加
	 */
	public function add_admin_menu() {
		// 管理者のみ表示
		if ( ! current_user_can( 'edit_posts' ) ) {
			return;
		}
		
		add_submenu_page(
			'edit.php',
			'レビュー待ち投稿・ページ',
			'レビュー待ち',
			'edit_posts',
			'prr-pending-posts',
			array( $this, 'render_admin_page' )
		);
	}
	
	/**
	 * 管理ページの内容を表示
	 */
	public function render_admin_page() {
		$pending_posts = $this->get_pending_posts();
		
		?>
		<div class="wrap">
			<h1>レビュー待ち投稿・ページ一覧</h1>
			
			<?php if ( empty( $pending_posts ) ) : ?>
				<p>現在、レビュー待ちの投稿・ページはありません。</p>
			<?php else : ?>
				<div class="prr-pending-posts-admin">
					<table class="wp-list-table widefat fixed striped">
						<thead>
							<tr>
								<th style="width: 30%;">タイトル</th>
								<th style="width: 15%;">投稿タイプ</th>
								<th style="width: 15%;">作成者</th>
								<th style="width: 20%;">更新日</th>
								<th style="width: 20%;">操作</th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $pending_posts as $post ) : 
								$post_type_obj = get_post_type_object( $post->post_type );
								$post_type_label = $post_type_obj ? $post_type_obj->labels->singular_name : $post->post_type;
								$post_author = get_userdata( $post->post_author );
								$author_name = $post_author ? $post_author->display_name : '不明';
								$edit_link = admin_url( 'post.php?post=' . $post->ID . '&action=edit' );
								$view_link = get_permalink( $post->ID );
								$date = get_the_modified_date( 'Y年m月d日 H:i', $post->ID );
							?>
								<tr>
									<td>
										<strong><?php echo esc_html( get_the_title( $post->ID ) ); ?></strong>
										<?php if ( $post->post_excerpt ) : ?>
											<br><small style="color: #666;"><?php echo esc_html( wp_trim_words( $post->post_excerpt, 20 ) ); ?></small>
										<?php endif; ?>
									</td>
									<td><?php echo esc_html( $post_type_label ); ?></td>
									<td><?php echo esc_html( $author_name ); ?></td>
									<td><?php echo esc_html( $date ); ?></td>
									<td>
										<a href="<?php echo esc_url( $edit_link ); ?>" class="button button-small">編集</a>
										<?php if ( $view_link ) : ?>
											<a href="<?php echo esc_url( $view_link ); ?>" class="button button-small" target="_blank">表示</a>
										<?php endif; ?>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}
	
	/**
	 * レビュー待ちの投稿を取得
	 */
	private function get_pending_posts( $limit = 50 ) {
		$args = array(
			'post_status' => 'pending',
			'post_type' => 'any',
			'posts_per_page' => $limit,
			'orderby' => 'modified',
			'order' => 'DESC',
		);
		
		$query = new WP_Query( $args );
		return $query->posts;
	}
	
	/**
	 * 管理画面のアセットを読み込む
	 */
	public function enqueue_admin_assets( $hook ) {
		// ダッシュボードと管理ページでのみ読み込む
		if ( 'index.php' !== $hook && strpos( $hook, 'prr-pending-posts' ) === false ) {
			// プロフィールページでも読み込む
			if ( 'profile.php' !== $hook && 'user-edit.php' !== $hook ) {
				return;
			}
		}
		
		wp_enqueue_style(
			'prr-admin-style',
			PRR_PLUGIN_URL . 'assets/admin.css',
			array(),
			PRR_VERSION
		);
		
		// プロフィールページではjQueryを確実に読み込む
		if ( 'profile.php' === $hook || 'user-edit.php' === $hook ) {
			wp_enqueue_script( 'jquery' );
		}
	}
	
	/**
	 * ユーザープロフィールページに通知設定を追加
	 */
	public function add_user_notification_setting( $user ) {
		// 管理者・編集者権限を持つユーザーのみ表示
		if ( ! user_can( $user, 'edit_posts' ) ) {
			return;
		}
		
		$receive_notifications = get_user_meta( $user->ID, 'prr_receive_notifications', true );
		// デフォルトでは有効（既存ユーザーへの後方互換性のため）
		$checked = ( $receive_notifications === '1' || $receive_notifications === '' ) ? 'checked' : '';
		
		$time_enabled = get_user_meta( $user->ID, 'prr_notification_time_enabled', true );
		$time_checked = $time_enabled === '1' ? 'checked' : '';
		
		$start_hour = get_user_meta( $user->ID, 'prr_notification_start_hour', true );
		$end_hour = get_user_meta( $user->ID, 'prr_notification_end_hour', true );
		
		// デフォルト値
		if ( empty( $start_hour ) && $start_hour !== 0 ) {
			$start_hour = 9;
		}
		if ( empty( $end_hour ) && $end_hour !== 0 ) {
			$end_hour = 18;
		}
		
		?>
		<h2>レビュー依頼通知設定</h2>
		<table class="form-table">
			<tr>
				<th scope="row">レビュー依頼通知を受け取る</th>
				<td>
					<label for="prr_receive_notifications">
						<input 
							type="checkbox" 
							name="prr_receive_notifications" 
							id="prr_receive_notifications" 
							value="1" 
							<?php echo esc_attr( $checked ); ?>
						/>
						投稿・ページが「レビュー待ち」状態になった際に通知メールを受け取る
					</label>
					<p class="description">
						この設定を有効にすると、投稿・カスタム投稿・固定ページが「レビュー待ち」状態になった際に通知メールが送信されます。
					</p>
				</td>
			</tr>
			<tr>
				<th scope="row">通知受信時間帯を設定</th>
				<td>
					<label for="prr_notification_time_enabled">
						<input 
							type="checkbox" 
							name="prr_notification_time_enabled" 
							id="prr_notification_time_enabled" 
							value="1" 
							<?php echo esc_attr( $time_checked ); ?>
						/>
						指定した時間帯のみ通知を受け取る
					</label>
					<p class="description">
						この設定を有効にすると、指定した時間帯内にのみ通知メールが送信されます。時間帯外の通知は、時間帯内になった時点で送信されます。
					</p>
					<div id="prr_notification_time_settings" style="margin-top: 10px; <?php echo $time_checked ? '' : 'display: none;'; ?>">
						<label>
							開始時刻:
							<select name="prr_notification_start_hour" id="prr_notification_start_hour">
								<?php for ( $i = 0; $i < 24; $i++ ) : ?>
									<option value="<?php echo esc_attr( $i ); ?>" <?php selected( $start_hour, $i ); ?>>
										<?php echo esc_html( sprintf( '%02d:00', $i ) ); ?>
									</option>
								<?php endfor; ?>
							</select>
						</label>
						～
						<label>
							終了時刻:
							<select name="prr_notification_end_hour" id="prr_notification_end_hour">
								<?php for ( $i = 0; $i < 24; $i++ ) : ?>
									<option value="<?php echo esc_attr( $i ); ?>" <?php selected( $end_hour, $i ); ?>>
										<?php echo esc_html( sprintf( '%02d:00', $i ) ); ?>
									</option>
								<?php endfor; ?>
							</select>
						</label>
					</div>
				</td>
			</tr>
		</table>
		<script>
		jQuery(document).ready(function($) {
			$('#prr_notification_time_enabled').on('change', function() {
				if ($(this).is(':checked')) {
					$('#prr_notification_time_settings').show();
				} else {
					$('#prr_notification_time_settings').hide();
				}
			});
		});
		</script>
		<?php
	}
	
	/**
	 * ユーザープロフィール設定を保存
	 */
	public function save_user_notification_setting( $user_id ) {
		// 権限チェック
		if ( ! current_user_can( 'edit_user', $user_id ) ) {
			return;
		}
		
		// 管理者・編集者権限を持つユーザーのみ保存
		$user = get_userdata( $user_id );
		if ( ! $user || ! user_can( $user, 'edit_posts' ) ) {
			return;
		}
		
		// 通知設定を保存
		if ( isset( $_POST['prr_receive_notifications'] ) && $_POST['prr_receive_notifications'] === '1' ) {
			update_user_meta( $user_id, 'prr_receive_notifications', '1' );
		} else {
			update_user_meta( $user_id, 'prr_receive_notifications', '0' );
		}
		
		// 時間帯設定を保存
		if ( isset( $_POST['prr_notification_time_enabled'] ) && $_POST['prr_notification_time_enabled'] === '1' ) {
			update_user_meta( $user_id, 'prr_notification_time_enabled', '1' );
			
			$start_hour = isset( $_POST['prr_notification_start_hour'] ) ? (int) $_POST['prr_notification_start_hour'] : 9;
			$end_hour = isset( $_POST['prr_notification_end_hour'] ) ? (int) $_POST['prr_notification_end_hour'] : 18;
			
			update_user_meta( $user_id, 'prr_notification_start_hour', $start_hour );
			update_user_meta( $user_id, 'prr_notification_start_minute', 0 );
			update_user_meta( $user_id, 'prr_notification_end_hour', $end_hour );
			update_user_meta( $user_id, 'prr_notification_end_minute', 0 );
		} else {
			update_user_meta( $user_id, 'prr_notification_time_enabled', '0' );
		}
	}
}

// プラグインの初期化
function prr_init() {
	Post_Review_Requestor::get_instance();
}
add_action( 'plugins_loaded', 'prr_init' );

// プラグイン有効化時にCronを登録
function prr_activate() {
	if ( ! wp_next_scheduled( 'prr_send_queued_notifications' ) ) {
		wp_schedule_event( time(), 'hourly', 'prr_send_queued_notifications' );
	}
}
register_activation_hook( __FILE__, 'prr_activate' );

// プラグイン無効化時にCronを削除
function prr_deactivate() {
	$timestamp = wp_next_scheduled( 'prr_send_queued_notifications' );
	if ( $timestamp ) {
		wp_unschedule_event( $timestamp, 'prr_send_queued_notifications' );
	}
}
register_deactivation_hook( __FILE__, 'prr_deactivate' );
