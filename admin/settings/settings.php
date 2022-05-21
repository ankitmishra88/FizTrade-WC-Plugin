<?php
	defined('ABSPATH') || die('No Script Kiddies Please');
	if(isset($_POST['settings'])){
		update_option('fiztrade_settings', $_POST['settings']);
	}
	$settings = get_option('fiztrade_settings');
	$settings = ! is_array($settings) ? array() : $settings;
	//print_r($settings);
?>
<div class='settings'>
	<form action='' method='POST'>
		<div class='single-setting'>
			<label>FizTrade API Token</label>
			<input type='text' value='<?php echo $settings['fiztrade_api_token']; ?>' name='settings[fiztrade_api_token]' value='' />
		</div>
		<div class='single-setting'>
			<label>FizTrade Chart Token</label>
			<input value='<?php echo $settings['fiztrade_chart_token']; ?>' type='text' name='settings[fiztrade_chart_token]' value='' />
		</div>
		<div class='single-setting'>
			<input <?php echo isset($settings['live_mode'])?'checked':''; ?> type='checkbox' name='settings[live_mode]' id='fiztrade-mode' />
			<label for='fiztrade-mode'>Check for Live</label>
		</div>
		<div class='submit'>
			<button type='submit' class='button'>
				Update
			</button>
		</div>
	</form>
</div>
<div class='cron_url_link'>
	<strong>Set a cron job to run in your system regularly at this url: </strong> <?php echo admin_url('admin-ajax.php?action=get_fzt_products'); ?>
</div>