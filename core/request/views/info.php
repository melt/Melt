<?php namespace melt\request; ?>
<?php $this->layout->enterSection("head"); ?>
<title><?php echo $this->topic; ?></title>
<?php $this->layout->exitSection(); ?>
<h1><?php echo $this->topic; ?></h1>
<?php echo $this->body; ?>