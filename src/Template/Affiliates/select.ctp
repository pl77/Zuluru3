<?php
$this->Html->addCrumb(__('Affiliates'));
$this->Html->addCrumb(__('Select'));
?>

<div class="affiliates form">
	<?= $this->Form->create(false, ['align' => 'horizontal']) ?>
	<fieldset>
		<p class="warning-message"><?= __('By selecting an affiliate below, you will only be shown that affiliate\'s details throughout the site. You will be able to remove this restriction or select another affiliate to browse, using links on your {0}.',
			__('Dashboard')
		) ?></p>
		<p class="warning-message"><?= __('Note that, regardless of which affiliate you may select, your {0} and menus will always show your teams and games.', __('Dashboard')) ?></p>
		<?= $this->Form->input('affiliate') ?>
	</fieldset>
	<?= $this->Form->button(__('Submit'), ['class' => 'btn-success']) ?>
	<?= $this->Form->end() ?>
</div>
