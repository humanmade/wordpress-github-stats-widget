<?php
class HMGithubWidget extends WP_Widget {

    private $stats;
    private $total_commits;
    private $last30;
    private $total_last30;
    private $_hm_gh;
    private $orgs;


    /**
     * Register widget with WordPress.
     */
    function __construct() {
        $this->stats = false;
        $this->total_commits = false;
        $this->last30 = false;
        $this->total_last30 = false;

        // Only get these if the class exists
        if(class_exists('HMGithubOAuth')) {
            $this->_hm_gh = HMGithubOAuth::get_instance();
            $this->orgs = $this->_hm_gh->get_orgs_for_widget();
        }

        parent::__construct(
            'hm_github_widget',
            __('Github Statistics', 'hm_github_widget_textdomain'),
            array( 'description' => __( 'Displays total commits and commits in last 30 days', 'hm_github_widget_textdomain' ), )
        );
        add_action( 'wp_enqueue_scripts', array( $this, 'add_style' ) );
    }


    /**
     * Include the CSS file
     */
    public function add_style() {
        wp_register_style( 'hm_github_css', plugins_url( 'github.widget.css' , __FILE__ ) );
        wp_enqueue_style( 'hm_github_css' );
    }


    /**
     * Front-end display of widget.
     *
     * @see WP_Widget::widget()
     *
     * @param array $args     Widget arguments.
     * @param array $instance Saved values from database.
     */
    public function widget( $args, $instance ) {
        $title = apply_filters( 'widget_title', $instance['title'] );
        $link = $instance['link'];

        echo $args['before_widget'];

        if ( isset( $instance[ 'orgs' ] ) ) {
            $orgs = $instance[ 'orgs' ];
        } else {
            $orgs = array();
        }
        $this->stats = $this->_hm_gh->get_stats_for_widget( $orgs );
        if(is_array($this->stats)) {
            $this->total_commits = array_sum( $this->stats );
        }
        $this->last30 = $this->get_last30();
        if(is_array($this->last30)) {
            $this->total_last30 = array_sum($this->last30);
        }

        if ( ! empty( $title ) ) {
            if( ! empty( $link ) ) {
                echo '<a href="' . esc_url( $link ) . '">';
            }
            echo $args['before_title'] . esc_attr( $title ) . $args['after_title'];
            if( ! empty( $link ) ) {
                echo '</a>';
            }
        }
        $id = wp_generate_password(5);
        ?>

        <div class="hm-github-graph cf">
            <?php
            $highest = $this->get_highest();
            foreach ($this->last30 as $commits) {
                $percentage = number_format( 100 * ($commits / $highest), 4);
                ?>
                <div class="bar" style="height: <?php echo $percentage; ?>%;"></div>
                <?php
            }
            ?>
        </div>
        <p class="hm-total-commits">
            <?php
            if( $this->total_commits ) {
                echo 'Total commits: ' . $this->total_commits . '.';
            } else {
                echo 'Total commits unavailable at this time.';
            }
            ?>
        </p>
        <p class="hm-last30-commits">
            <?php
            if( $this->total_last30 ) {
                echo $this->total_last30 . ' commits in last 30 days.';
                echo ' <span>Commits per day: ' . number_format( (intval( $this->total_last30 ) / 30 ), 2) . '</span>';
            }
            ?>
        </p>
        <?php
        echo $args['after_widget'];
    }


    /**
     * Back-end widget form.
     *
     * @see WP_Widget::form()
     *
     * @param array $instance Previously saved values from database.
     */
    public function form( $instance ) {
        if ( isset( $instance[ 'title' ] ) ) {
            $title = $instance[ 'title' ];
        } else {
            $title = __( 'New title', 'hm_github_widget_textdomain' );
        }

        if ( isset( $instance[ 'orgs' ] ) ) {
            $orgs = $instance[ 'orgs' ];
        } else {
            $orgs = array();
        }


        if ( isset( $instance[ 'link' ] ) ) {
            $link = $instance[ 'link' ];
        } else {
            $link = '';
        }

        $this->stats = $this->_hm_gh->get_stats_for_widget( $orgs );
        ?>
        <p>
            <label for="<?php echo $this->get_field_id( 'title' ); ?>"><?php _e( 'Title:' ); ?></label>
            <input class="widefat" id="<?php echo $this->get_field_id( 'title' ); ?>" name="<?php echo $this->get_field_name( 'title' ); ?>" type="text" value="<?php echo esc_attr( $title ); ?>">
        </p>

        <p>
            <label for="<?php echo $this->get_field_id( 'link' ); ?>"><?php _e( 'URL to link to:' ); ?></label>
            <input class="widefat" id="<?php echo $this->get_field_id( 'link' ); ?>" name="<?php echo $this->get_field_name( 'link' ); ?>" type="text" value="<?php echo esc_url( $link ); ?>">
        </p>

        <?php
        if($this->orgs) {
            ?>
            <p>
                <label>Select an Organisation to show aggregate stats for</label>
                <ul>
                    <?php
                    foreach ( $this->orgs as $org ) {
                        $_v = $org->repos_url;
                        $id = wp_generate_password(5);

                        ?>
                        <li>
                            <label for="<?php echo $this->get_field_id( 'orgs' ) . $id; ?>">
                                <input id="<?php echo $this->get_field_id( 'orgs' ) . $id; ?>" type="checkbox" name="<?php echo $this->get_field_name('orgs'); ?>[]" value="<?php echo $_v; ?>" <?php $this->multi_checked( $orgs, $_v); ?>>
                                <?php echo $org->login;?>
                            </label>
                        </li>
                        <?php
                    }
                    ?>
                </ul>
            </p>
            <?php
        } else {
            ?>
            <p>You have no organisations enabled. See to <a href="<?php echo admin_url('profile.php'); ?>">your profile page</a> first!</p>
            <?php
        }
    }


    /**
     * Sanitize widget form values as they are saved.
     *
     * @see WP_Widget::update()
     *
     * @param array $new_instance Values just sent to be saved.
     * @param array $old_instance Previously saved values from database.
     *
     * @return array Updated safe values to be saved.
     */
    public function update( $new_instance, $old_instance ) {
        $instance = array();
        $instance['title'] = ( ! empty( $new_instance['title'] ) ) ? esc_attr( $new_instance['title'] ) : '';
        $instance['link'] = ( ! empty( $new_instance['link'] ) ) ? esc_url( $new_instance['link'] ) : '';
        $instance['orgs'] = ( ! empty( $new_instance['orgs'] ) ) ? $new_instance['orgs'] : false;

        return $instance;
    }


    /**
     * Once I have the stats, I'm rearranging the numbers to get the last 30 days
     * @return array containing the last 30 days' worth of commit data
     */
    private function get_last30() {
        $stats = $this->stats;
        if(empty($stats)) {
            return;
        }
        krsort( $stats );
        $stats = array_slice( $stats, 0, 30 );
        return $stats;
    }


    /**
     * Returns the highest value in the last 30 days of commit history. Used in the
     * bargraph css.
     * @return int/string the highest value
     */
    private function get_highest() {
        $temparray = $this->last30;
        if(empty($temparray)) {
            return;
        }
        rsort( $temparray );
        return $temparray[0];
    }


    /**
     * A helper function for multi checkboxes
     * @param  array            $is         what we have stored in the database
     * @param  string           $input      individual value of the checkbox
     * @return void                         echoes
     */
    public function multi_checked( $is, $input ) {
        if( is_array( $is ) ) {
            if( in_array( $input, $is ) ) {
                echo ' checked="checked"';
            }
        }
    }
}


/**
 * Function that registers the widget. Self documenting code is self documenting.
 */
function register_hm_github_widget() {
    register_widget( 'HMGithubWidget' );
}
add_action( 'widgets_init', 'register_hm_github_widget' );
