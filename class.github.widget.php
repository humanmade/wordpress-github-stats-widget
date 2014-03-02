<?php




class HMGithubWidget extends WP_Widget {

    private $stats;
    private $total_commits;
    private $last30;

    /**
     * Register widget with WordPress.
     */
    function __construct() {
        $this->stats = false;
        $this->total_commits = false;
        if(class_exists('HMGithubOAuth')) {
            $_stats = HMGithubOAuth::get_instance();
            $this->stats = $_stats->get_stats_for_widget();
            $this->total_commits = array_sum( $this->stats );
        }
        $this->last30 = $this->get_last30();

        // wp_die( es_preit( array( $this->stats, $this->total_commits ), false ) );
        parent::__construct(
            'hm_github_widget', // Base ID
            __('Github Statistics', 'hm_github_widget_textdomain'), // Name
            array( 'description' => __( 'Displays total commits and commits in last 30 days', 'hm_github_widget_textdomain' ), ) // Args
        );


        add_action( 'wp_enqueue_scripts', array( $this, 'add_style' ) );
    }

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

        if ( ! empty( $title ) ) {
            echo $args['before_title'] . $title . $args['after_title'];
        }

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
        <?php

        if( $this->total_commits ) {

            echo 'Total commits: ' . $this->total_commits;
        } else {
            echo 'Total commits unavailable at this time';
        }
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
        }
        else {
            $title = __( 'New title', 'hm_github_widget_textdomain' );
        }

        if ( isset( $instance[ 'link' ] ) ) {
            $link = $instance[ 'link' ];
        }
        else {
            $link = __( 'New link', 'hm_github_widget_textdomain' );
        }



        ?>
        <p>
        <label for="<?php echo $this->get_field_id( 'title' ); ?>"><?php _e( 'Title:' ); ?></label>
        <input class="widefat" id="<?php echo $this->get_field_id( 'title' ); ?>" name="<?php echo $this->get_field_name( 'title' ); ?>" type="text" value="<?php echo esc_attr( $title ); ?>">
        </p>

        <p>
        <label for="<?php echo $this->get_field_id( 'link' ); ?>"><?php _e( 'Title:' ); ?></label>
        <input class="widefat" id="<?php echo $this->get_field_id( 'link' ); ?>" name="<?php echo $this->get_field_name( 'link' ); ?>" type="text" value="<?php echo esc_attr( $link ); ?>">
        </p>
        <?php
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
        $instance['title'] = ( ! empty( $new_instance['title'] ) ) ? strip_tags( $new_instance['title'] ) : '';

        return $instance;
    }

    private function get_last30() {
        $stats = $this->stats;
        krsort( $stats );
        $stats = array_slice( $stats, 0, 30 );
        return $stats;
    }

    private function get_highest() {
        $temparray = $this->last30;
        rsort( $temparray );
        return $temparray[0];
    }

} // class Foo_Widget



function register_foo_widget() {
    register_widget( 'HMGithubWidget' );
}
add_action( 'widgets_init', 'register_foo_widget' );