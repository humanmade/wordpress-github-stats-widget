# HM GitHub Stats

## Configuration
Add the following to your `wp-config.php`, but replace as needed with your
actual username/password:

```php
define( 'HMG_USERNAME', 'willmot' );
define( 'HMG_PASSWORD', 'hunter2' );
define( 'HMG_ORGANISATION', 'humanmade' );
```

## Usage
```php

<div class="stat-commits" style="position:relative;">

    <div id="graph-commits" style="position:absolute;top:0;right:0;"></div>

    <div class="stat-wrap">
    	<a href="https://github.com/humanmade"><div class="stat-num"><?php echo ( $commits = array_sum( hmg_get_formatted_commits_for_month_cached() ) ) ? $commits : '&hellip;'; ?></div></a>
    </div>

    <div class="stat-comment">
        <span style="float:left">github commits this month</span>
        30 days / <span class="active"><strong><?php echo ( $average = hmg_commits_by_day_average() ) ? $average : '&hellip;'; ?></strong> commits per day</span>

    </div>

</div>

```

## Contribution guidelines ##

See https://github.com/humanmade/hm-github-stats/blob/master/CONTRIBUTING.md