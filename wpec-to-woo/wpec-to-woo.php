<?php
/*
Plugin Name: wp-e-commerce to woocommerce conversion
Plugin URI: ralcus.com
Version: 0.1
Author: ralcus
Description: converts wp-e-commerce based shops to be compatible with woocommerce
*/

if (!class_exists("ralc_wpec_to_woo")) {

  class ralc_wpec_to_woo {

    var $products; // stores all the product posts
    var $old_post_type = 'wpsc-product'; //wpsc-product
    var $log; // stores a log of actions taken by the script during conversion
    // just get the id of the first administrator in the database
    var $post_author;

    // Set defaults for Shipping & Billing country
    var $default_shipping_country = 'US';
    var $default_billing_country = 'US';
    var $default_order_currency = 'USD';

    var $taxes_included = false;

    public function __construct() { 
      //
    }

    protected function disable_emails() {
      /**
       * Hooks for sending emails during store events
       * From: https://docs.woocommerce.com/document/unhookremove-woocommerce-emails/
       **/

      if( class_exists('WC_Emails') ) {

        $email_class = \WC_Emails::instance();

        remove_action( 'woocommerce_low_stock_notification', array( $email_class, 'low_stock' ) );
        remove_action( 'woocommerce_no_stock_notification', array( $email_class, 'no_stock' ) );
        remove_action( 'woocommerce_product_on_backorder_notification', array( $email_class, 'backorder' ) );
        
        // New order emails
        remove_action( 'woocommerce_order_status_pending_to_processing_notification', array( $email_class->emails['WC_Email_New_Order'], 'trigger' ) );
        remove_action( 'woocommerce_order_status_pending_to_completed_notification', array( $email_class->emails['WC_Email_New_Order'], 'trigger' ) );
        remove_action( 'woocommerce_order_status_pending_to_on-hold_notification', array( $email_class->emails['WC_Email_New_Order'], 'trigger' ) );
        remove_action( 'woocommerce_order_status_failed_to_processing_notification', array( $email_class->emails['WC_Email_New_Order'], 'trigger' ) );
        remove_action( 'woocommerce_order_status_failed_to_completed_notification', array( $email_class->emails['WC_Email_New_Order'], 'trigger' ) );
        remove_action( 'woocommerce_order_status_failed_to_on-hold_notification', array( $email_class->emails['WC_Email_New_Order'], 'trigger' ) );
        
        // Processing order emails
        remove_action( 'woocommerce_order_status_pending_to_processing_notification', array( $email_class->emails['WC_Email_Customer_Processing_Order'], 'trigger' ) );
        remove_action( 'woocommerce_order_status_pending_to_on-hold_notification', array( $email_class->emails['WC_Email_Customer_Processing_Order'], 'trigger' ) );
        
        // Completed order emails
        remove_action( 'woocommerce_order_status_completed_notification', array( $email_class->emails['WC_Email_Customer_Completed_Order'], 'trigger' ) );
          
        // Note emails
        remove_action( 'woocommerce_new_customer_note_notification', array( $email_class->emails['WC_Email_Customer_Note'], 'trigger' ) );

      }
      

      // Turn off E-mails not included in the above, from wc-update-functions.php
      remove_all_actions( 'woocommerce_order_status_refunded_notification' );
      remove_all_actions( 'woocommerce_order_partially_refunded_notification' );
      remove_action( 'woocommerce_order_status_refunded', array( 'WC_Emails', 'send_transactional_email' ) );
      remove_action( 'woocommerce_order_partially_refunded', array( 'WC_Emails', 'send_transactional_email' ) );


    }

    /**
     * Adds WP e-Commerce Metadata table names to the $wpdb object so that WP's 
     * get_metadata() works.
     * 
     * @return void
     */
    function enable_wpec_meta() {

      global $wpdb;
      
      if( empty( $wpdb->wpsc_meta ) ) {
        $wpdb->wpsc_meta                = $wpdb->prefix . 'wpsc_meta';
      }
      if( empty( $wpdb->wpsc_cart_itemmeta ) ) {
        $wpdb->wpsc_cart_itemmeta       = $wpdb->prefix . 'wpsc_cart_item_meta';
      }
      if( empty( $wpdb->wpsc_purchasemeta ) ) {
        $wpdb->wpsc_purchasemeta        = $wpdb->prefix . 'wpsc_purchase_meta';
      
      }
      if( empty( $wpdb->wpsc_visitormeta ) ) {
        $wpdb->wpsc_visitormeta         = $wpdb->prefix . 'wpsc_visitor_meta';
      }
    }

    
    public function plugin_menu() {
      $page = add_submenu_page( 'tools.php', 'wpec to woo', 'wpec to woo', 'manage_options', 'wpec-to-woo', array( $this, 'plugin_options' ) );
      add_action( 'admin_print_styles-' . $page, array( $this, 'admin_styles' ) );

      $help = '<p>The idea is you run this on a wordpress shop already setup with wp-e-commerce. Then this code will convert as much as it can into a woocommerce a shop. Make sure you have the Woocommerce plugin activated.</p>';
      $help .= '<p>Currently only converting products and categories, plan to try and convert the orders too. Because the sites i\'m writing this for don\'t have any variations on products i have not taken the time to work out a system for them. It also sets all products tax status to \'taxable\' and the tax class to \'standard\' regardless.</p>';          
      $help .= '<p><b>One last caveat:</b> i\'m working with version:3.8.6 of wp-e-commerce, things may well have changed with the lastest version but the shops i need to convert are on this version and i\'m not interested in trying to upgrade them because of the many problems i have been having each time i upgrade the wp-e-commerce plugin. I\'ll test the plugin with the latest verion at a later date.</p>';
      get_current_screen( $page, $help );
    }// END: plugin_menu
    
    public function admin_styles() {
      wp_enqueue_style( 'wpec_to_woo_styles' );
    }
    
    public function admin_init() {
      wp_register_style( 'wpec_to_woo_styles', plugins_url('styles.css', __FILE__) );
    }

    public function plugin_options() {
      if (!current_user_can('manage_options'))  {
        wp_die( __('You do not have sufficient permissions to access this page.') );
      }

      ?>
      <div class="wrap">
        <h2>Wp-e-commerce to woocommerce converter</h2>
        <p>Use at your own risk!, still working on it, only use it on a test version of your site. Read the help for more information.</p>         
        <?php
        if( isset($_POST['delete_orders']) && $_POST['delete_orders'] == 'yes' ){
          $this->delete_orders();
        }
        if( isset($_POST['order']) && $_POST['order'] == 'go_go_go' ){
          $this->conversion();
        }
        $this->at_a_glance();
        ?>
        <form method="post" action="tools.php?page=wpec-to-woo">
          <input type="hidden" name="order" value="go_go_go" />
          <p>
            <input type="checkbox" name="delete_orders" value="yes" checked="checked" />
            Delete all orders
          </p>
          <input class="button-primary" type="submit" value="Convert My Store" />
        </form>
        <?php
        if( isset($_POST['order']) && $_POST['order'] == 'go_go_go' ){          
          $this->show_log();
        }
        ?>
      </div><!-- .wrap -->
      <?php
      } //END: plugin_options
        
    public function at_a_glance(){
      global $wpdb; global $woocommerce;
      ?>
      <div id="glance" class="metabox-holder">

        <!-- woocommerce at a glance -->
        <div class="postbox-container">
          <div class="postbox">
            <div class="handlediv" title="Click to toggle"></div>
            <h3 class="hndle"><span>Your WooCommerce Shop At a Glance</h3>
            <div class="inside">

              <div class="table table_content">
                <p class="sub">Content</p>
                <table>
                  <tbody>
                    <tr class="first">
                      <td class="b first"><a href="edit.php?post_type=product">
                        <?php 
                        $woo_products = wp_count_posts( 'product' );
                        echo number_format_i18n( $woo_products->publish ); 
                        ?>
                      </a></td>
                      <td class="t"><a href="edit.php?post_type=product">Products<a/></td>
                    </tr>
                    <tr>
                      <td class="b first"><a href="edit-tags.php?taxonomy=product_cat&post_type=product"><?php echo wp_count_terms('product_cat') ?></a></td>
                      <td class="t"><a href="edit-tags.php?taxonomy=product_cat&post_type=product">Product Categories</a></td>
                    </tr>
                    <tr>
                      <td class="b first"><a href="edit-tags.php?taxonomy=product_tag&post_type=product"><?php echo wp_count_terms('product_tag'); ?></a></td>
                      <td class="t"><a href="edit-tags.php?taxonomy=product_tag&post_type=product">Product Tags</a></td>
                    </tr>
                    <tr>
                      <td class="b first">
                        <a href="admin.php?page=woocommerce_attributes">
                        <?php
                          if (method_exists($woocommerce, 'get_attribute_taxonomies')) {
                            $taxonomies = $woocommerce->get_attribute_taxonomies();
                          } else {
                            $taxonomies = wc_get_attribute_taxonomies();
                          }
                          echo sizeof($taxonomies);
                        ?>
                        </a>
                      </td>
                      <td class="t"><a href="admin.php?page=woocommerce_attributes">Attribute taxonomies</a></td>
                    </tr>
                    <tr class="first">
                      <td class="b first"><a href="edit.php?post_type=shop_coupon">
                        <?php
                        $woo_coupons = wp_count_posts( 'shop_coupon' );
                        echo number_format_i18n( $woo_coupons->publish );                                
                        ?>
                      </a></td>
                      <td class="t"><a href="edit.php?post_type=shop_coupon">Coupons</a></td>
                    </tr>
                  </tbody>
                </table>
              </div><!-- .table --> 

              <div class="table table_orders">
                <p class="sub orders_sub">Orders</p>
                <?php
                // count orders
                $results = $wpdb->get_results("
                  SELECT t.name, t.slug, COUNT( tr.term_taxonomy_id ) AS nb
                  FROM $wpdb->posts p, $wpdb->term_relationships tr, $wpdb->term_taxonomy tt, $wpdb->terms t
                  WHERE p.post_type =  'shop_order'
                  AND p.ID = tr.object_id
                  AND tr.term_taxonomy_id = tt.term_taxonomy_id
                  AND t.term_id = tt.term_id
                  AND tt.taxonomy =  'shop_order_status'
                  GROUP BY tr.term_taxonomy_id
                  ", ARRAY_A);
                $woocommerce_orders = array(
                  'pending' => 0,
                  'processing' => 0,
                  'completed' => 0,
                  'on-hold' => 0,
                  );
                foreach($results as $res){
                  $woocommerce_orders[$res['slug']] = $res['nb'];
                }
                ?>
                <table>
                  <tbody>
                    <tr class="first">
                      <td class="b first"><a href="edit.php?post_type=shop_order&shop_order_status=pending"><?php echo $woocommerce_orders['pending']; ?></a></td>
                      <td class="t"><a href="edit.php?post_type=shop_order&shop_order_status=pending" class="pending">Pending<a/></td>
                    </tr>
                    <tr>
                      <td class="b first"><a href="edit.php?post_type=shop_order&shop_order_status=on-hold"><?php echo $woocommerce_orders['on-hold']; ?></a></td>
                      <td class="t"><a href="edit.php?post_type=shop_order&shop_order_status=on-hold" class="onhold">On-Hold<a/></td>
                    </tr>
                    <tr>
                      <td class="b first"><a href="edit.php?post_type=shop_order&shop_order_status=processing"><?php echo $woocommerce_orders['processing']; ?></a></td>
                      <td class="t"><a href="edit.php?post_type=shop_order&shop_order_status=processing" class="processing">Processing</a></td>
                    </tr>
                    <tr>
                      <td class="b first"><a href="edit.php?post_type=shop_order&shop_order_status=completed"><?php echo $woocommerce_orders['completed']; ?></a></td>
                      <td class="t"><a href="edit.php?post_type=shop_order&shop_order_status=completed" class="complete">Completed</a></td>
                    </tr>
                  </tbody>
                </table>
              </div><!-- .table --> 

            </div><!-- .inside -->
          </div><!-- .postbox -->
        </div><!-- .postbox-container -->

        <!-- wpec at a glance -->
        <div class="postbox-container">
          <div class="postbox">
            <div class="handlediv" title="Click to toggle"></div>
            <h3 class="hndle"><span>Your WPEC Shop At a Glance</h3>
            <div class="inside">

              <div class="table table_content">
                <p class="sub">Content</p>
                <table>
                  <tbody>
                    <tr class="first">
                      <td class="b first"><a href="#">
                        <?php 
                        $wpec_products = wp_count_posts( 'wpsc-product' );
                        echo number_format_i18n( isset( $wpec_products->publish ) ? $wpec_products->publish : 0 ); 
                        ?>
                      </a></td>
                      <td class="t"><a href="#">Products<a/></td>
                    </tr>
                    <tr>
                      <td class="b first"><a href="#">
                        <?php 
                        $wpec_categories = wp_count_terms('wpsc_product_category');
                        echo ( isset($wpec_categories->errors) ? 0 : $wpec_categories );
                        ?>
                      </a></td>
                      <td class="t"><a href="#">Product Categories</a></td>
                    </tr>
                    <tr>
                      <td class="b first"><a href="#"><?php echo wp_count_terms('product_tag'); ?></a></td>
                      <td class="t"><a href="#">Product Tags</a></td>
                    </tr>
                    <tr>
                      <td class="b first"><a href="#">
                        <?php
                        $wpec_coupons = $wpdb->get_var( "
                          SELECT COUNT(*) FROM " . $wpdb->prefix . "wpsc_coupon_codes
                          ");
                        echo $wpec_coupons;
                        ?>
                      </a></td>
                      <td class="t"><a href="#">Coupons</a></td>
                    </tr>
                  </tbody>
                </table>
              </div><!-- .table -->
              <?php
              $wpec_order_table = $wpdb->prefix . 'wpsc_purchase_logs';
              $wpec_pending = $wpdb->get_var( "
                SELECT COUNT(*) 
                FROM " . $wpec_order_table . " 
                WHERE processed = '2'
                ");
              $wpec_processing = $wpdb->get_var( "
                SELECT COUNT(*) 
                FROM " . $wpec_order_table . "
                WHERE processed = '3' 
                OR processed = '4'
                ");
              $wpec_completed = $wpdb->get_var( "
                SELECT COUNT(*) 
                FROM " . $wpec_order_table . "
                WHERE processed = '5'
                ");
                ?>
                <div class="table table_orders">
                  <p class="sub orders_sub">Orders</p>
                  <table>
                    <tbody>
                      <tr class="first">
                        <td class="b first"><a href="edit.php?post_type=product"><?php echo $wpec_pending ?></a></td>
                        <td class="t"><a href="#" class="pending">Pending<a/></td>
                      </tr>
                      <tr>
                        <td class="b first"><a href="#">0</a></td>
                        <td class="t"><a href="#" class="onhold">On-Hold<a/></td>
                      </tr>
                      <tr>
                        <td class="b first"><a href="#"><?php echo $wpec_processing ?></a></td>
                        <td class="t"><a href="#" class="processing">Processing</a></td>
                      </tr>
                      <tr>
                        <td class="b first"><a href="#"><?php echo $wpec_completed ?></a></td>
                        <td class="t"><a href="#" class="complete">Completed</a></td>
                      </tr>
                    </tbody>
                  </table>              
                </div><!-- .table -->

              </div><!-- .inside -->

              <div class="inside">
                <p><strong>Note:</strong> If WP e-Commerce is currently deactivated then Products and Product Categories will show zero even if there are products and categories in the database.</p>
              </div>
            </div><!-- .postbox -->
          </div><!-- .postbox-container -->

        </div><!-- #glance -->
        <?php
    } // at_a_glance()

    public function conversion(){ 
    	global $wpdb;

      // Don't send E-mail by accident while converting. 
      // We don't want customers getting a bunch of E-mails!
      $this->disable_emails();

      // Make get_metadata() work for WPeC's meta tables, even if WPeC is not activated.
      $this->enable_wpec_meta();

      // Load the Taxes option.
      $taxes = get_option('wpec_taxes_inprice');
      if( 'exclusive' == $taxes ) {
        $this->taxes_included = false;
      } else {
        $this->taxes_included = true;
      }
      // just get the id of the first administrator in the database
    	$this->post_author = $wpdb->get_var( "SELECT ID FROM $wpdb->users;" );
    	$this->update_shop_settings();          
    	$this->update_products();
    	$this->update_categories(); 
    	$this->update_coupons();
    	$this->update_orders();
      // tags don't need to be updated as both wpec and woo use the same name for the taxonomy 'product_tag'
      // $this->delete_redundant_wpec_datbase_entries();         
    }// END: conversion

    public function show_log(){
?>
      <div id="log" class="metabox-holder">
        <div class="postbox">
          <div class="handlediv" title="Click to toggle"></div>
          <h3 class="hndle"><span>Conversion Log</h3>
          <div class="inside">

            <div class="segment">
              <h4>Products</h4>
              <h5><?php echo count( $this->log["products"] ) ?> products updated</h5>
              <table>
                <tbody>
                  <?php if( $this->log["products"] ): ?>
                  <tr>
                    <th>ID</th>
                    <th>Title</th>
                  </tr>
                  <?php foreach( $this->log["products"] as $product ): ?>
                  <tr>
                    <td><?php echo $product["id"] ?></td>
                    <td><a href="post.php<?php echo $product["link"] ?>"><?php echo $product["title"] ?></a></td>
                  </tr>
                  <?php endforeach; ?>
                  <?php endif; ?>
                </tbody>
              </table>
            </div><!-- .segment -->

            <?php if( $this->log["categories"] ): ?>
            <div class="segment">
              <h4>Categories</h4>
              <h5><?php echo $this->log["categories"]["updated"] ?> categories updated</h5>
              <table>
                <tbody>
                  <tr>
                  </tr>
                </tbody>
              </table>
            </div><!-- .segment -->
            <?php endif; ?>

            <div class="segment">
              <h4>Coupons</h4>
              <h5><?php echo count( $this->log["coupons"] ) ?> coupons updated</h5>
              <table>
                <tbody>
                  <?php if( $this->log["coupons"] ): ?>                        
                  <?php foreach( $this->log["coupons"] as $coupon ): ?>
                  <tr>
                    <th>Title</th>
                    <th>Active</th>
                    <th>Notices</th>
                  </tr>
                  <tr>
                    <td><a href="post.php/<?php echo $coupon["link"] ?>"><?php echo $coupon["title"] ?></a></td>
                    <td><?php echo (isset($coupon['active']) ? $coupon['active'] : '' ); ?></td>
                    <?php if(isset($coupon["conditions"]) && $coupon["conditions"] && isset($coupon["free-shipping"]) && $coupon["free-shipping"] ): ?>
                    <td>This coupon was set to be in-active because it currently makes use of the conditions feature and the free shipping of wpec which is not supported by woocommerce</td>
                    <?php elseif(isset($coupon["conditions"]) && $coupon["conditions"]): ?>
                    <td>This coupon was set to be in-active because it currently makes use of the conditions feature of wpec which is not supported by woocommerce</td>
                    <?php elseif(isset($coupon["free-shipping"]) && $coupon["free-shipping"]): ?>
                    <td>This coupon was set to be in-active because it currently makes use of the free shipping feature of wpec which is not supported by woocommerce</td>
                    <?php endif; ?>
                  </tr>
                  <?php endforeach; ?>
                  <?php endif; ?>
                </tbody>
              </table>
            </div><!-- .segment -->

            <div class="segment">
              <h4>Orders</h4>
              <h5><?php echo count( $this->log["orders"] ) ?> orders updated</h5>
              <table>
                <tbody>
                <?php if( $this->log["orders"] ): ?>
                  <tr>
                    <th>Name</th>
                  </tr>
                  <?php foreach( $this->log["orders"] as $order ): ?>
                  <tr>
                    <td><?php echo $order["name"] ?></td>
                  </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
              </table>
            </div><!-- .segment -->

          </div><!-- .inside -->
        </div><!-- .postbox -->
      </div><!-- #log -->
<?php
  
  }

    /*
     * convert post type to woocommerce post type
     * update price field meta
     */
    public function update_products(){
      $args = array( 
        'post_type' => $this->old_post_type, 
        'posts_per_page' => -1,
        'post_status' => array('publish','inherit','pending','draft','auto-draft','future','private','trash')            
        );
      $products = new WP_Query( $args );
      $count = 0;
      // wp-e stores all the featured products in one place
      $featured_products = get_option('sticky_products', false);

      while ( $products->have_posts() ) : 
        set_time_limit(120);
        $post = $products->next_post();
      $post_id = $post->ID;
      $count ++;

      // ______ POST TYPE ______
      // WPeC stores variations as posts of type 'wpsc-product' and a post_status of 'inherit'.
      // Woo stores variations as a type of 'product_variation', and post_status of whatever the parent product's status is.
      if( empty( $post->post_parent ) ) {
        set_post_type( $post_id , 'product');  
      } else {
        $post->post_type = 'product_variation';
        $post->post_status = get_post_status( $post->post_parent );
        wp_update_post( $post );
      }                                
      // ______________________________


      // get the serialized wpec product metadata
      $_wpsc_product_metadata = get_post_meta($post_id, '_wpsc_product_metadata', true);


      // ______ PRICE ______ 
      $regular_price = get_post_meta($post_id, '_wpsc_price', true);
      update_post_meta($post_id, '_regular_price', $regular_price);               
      $sale_price = get_post_meta($post_id, '_wpsc_special_price', true);
      if( !empty($sale_price) && $sale_price != $regular_price ){
        update_post_meta($post_id, '_price', $sale_price);
        update_post_meta($post_id, '_sale_price', $sale_price);
      }else{
        update_post_meta($post_id, '_price', $regular_price);
      }
      // ______________________________


      // ______ INVENTORY ______
      $stock = get_post_meta($post_id, '_wpsc_stock', true);
      if( $stock != '' ){              
        $manage_stock = 'yes'; 
        $backorders = 'no';              
        if( (int)$stock > 0 ){
          $stock_status = 'instock';
        }else{
          $stock_status = 'outofstock';
        }
      }else{
        $manage_stock = 'no';
        $backorders = 'yes';
        $stock_status = 'instock';
      }
      // stock qty
      update_post_meta($post_id, '_stock', $stock);
      // stock status
      update_post_meta($post_id, '_stock_status', $stock_status);
      // manage stock
      update_post_meta($post_id, '_manage_stock', $manage_stock);  
      // backorders
      update_post_meta($post_id, '_backorders', $backorders);            
      // ______________________________


      // ______ PRODUCT TYPE ______
      // It looks like Woo doesn't store a product type for variations.
      if( empty( $post->post_parent ) ) {

        if( $this->wpec_product_has_variations( $post->ID ) ) {
          $product_type = 'variable';
        } else {
          $product_type = 'simple';
        }
        wp_set_object_terms($post_id, $product_type, 'product_type');
      }
      
      // ______________________________


      // ______ VISIBILITY ______
      if( $stock_status == 'instock' ){
        $visibility = 'visible';
      }else{
        $visibility = 'hidden';
      }
      // visibility
      update_post_meta($post_id, '_visibility', $visibility);
      // ______________________________


      // ______ OTHER PRODUCT DATA ______
      // sku code
      $sku = get_post_meta($post_id, '_wpsc_sku', true);
      if( $sku == null && !empty($_wpsc_product_metadata['_wpsc_sku'])){
        // try the old name
        $sku = $_wpsc_product_metadata['_wpsc_sku'];
      }
      update_post_meta($post_id, '_sku', $sku);            

      // tax status
      $tax_status = 'taxable';
      update_post_meta($post_id, '_tax_status', $sku);
      // tax class empty sets it to stndard
      $tax_class = '';
      update_post_meta($post_id, '_tax_class', $sku);

      if( isset( $_wpsc_product_metadata['weight'])) {
        // weight
        $weight = $_wpsc_product_metadata['weight'];

        update_post_meta($post_id, '_weight', $weight);
      }


      /*
       * WPEC use to use ['_wpsc_dimensions'] but then changed to use ['dimensions']
       * some products may still have the old variable name
       */
      $dimensions = null;
      if( isset( $_wpsc_product_metadata['dimensions'] ) ) {
        $dimensions = $_wpsc_product_metadata['dimensions'];
      } else if( isset( $_wpsc_product_metadata['_wpsc_dimensions'] )) {
        $dimensions = $_wpsc_product_metadata['_wpsc_dimensions'];
      }
      if( !empty($dimensions) ) {
        // height
        $height = $dimensions['height'];
        update_post_meta($post_id, '_height', $height);
        //length
        $length = $dimensions['length'];
        update_post_meta($post_id, '_length', $length);
        //width
        $width = $dimensions['width'];
        update_post_meta($post_id, '_width', $width);
      }
      /* woocommerce option update, weight unit and dimentions unit */
      if( $count == 1 ){
        /*
         * wpec stores weight unit and dimentions on a per product basis
         * as i expect most shops will use the same values for all products we can just take a single product
         * and just use those values for the global values used store wide in woocommerce
         */
        $weight_unit = $_wpsc_product_metadata['weight_unit'];

        if( $weight_unit == "pound" || $weight_unit == "ounce" || $weight_unit == "gram" ){
        	$weight_unit = "lbs";
        }else{
        	$weight_unit = "kg";
        }
        update_option( 'woocommerce_weight_unit', $weight_unit );

        $dimensions_unit = "in";
        if( !empty( $dimensions ) && isset($dimensions['height_unit']) ) {

          $dimensions_unit = $dimensions['height_unit'];

          if( $dimensions_unit == "cm" || $dimensions_unit == "meter" ){
            $dimensions_unit = "cm";
          }
        }
        update_option( 'woocommerce_dimension_unit', $dimensions_unit );
      }


        // featured?
      if( is_array( $featured_products ) ) {
        if (in_array($post_id, $featured_products)) {
          $featured = 'yes';
        }else{
          $featured = 'no';
        }
        update_post_meta($post_id, '_featured', $featured);
      }       
      // ______________________________


      // ______ PRODUCT IMAGES ______
      /*
      * if products have multiple images wpec puts those pictures into the post gallery
      * because of this those pictures don't need to be ammended and should still be working
      * we only need to update the featured image
      * wpec uses the first one in the galley as the product image so we just have to set that as 
      * the featured image
      */
      $args = array( 'post_type' => 'attachment', 'numberposts' => 1, 'post_status' => null, 'post_parent' => $post_id, 'post_mime_type' => 'image' );
      $attachments = get_posts($args);
      if ($attachments) {
        foreach ( $attachments as $attachment ) {
          set_post_thumbnail( $post_id, $attachment->ID );
        }
      }
      // ______________________________

      // add product to log
      $this->log["products"][] = array(
        "id" => $post_id, 
        "title" => get_the_title(),
        "link" => "?post=". $post_id ."&action=edit"
        );
      endwhile;

    }// END: update_products
    

    /**
     * Utility function to find out if WPeC product has variations.
     *
     * Pretty much lifted directly from the WPeC function wpsc_product_has_variations();
     * 
     * @param  int $post_id The ID of the product we're checking for variations.
     * @return bool          True if the product has variations. Fale if not.
     */
    public function wpec_product_has_variations( $post_id ) {
      static $has_variations = array();


      if ( ! isset( $has_variations[ $post_id ] ) ) {
        $args = array(
          'post_parent' => $post_id,
          'post_type'   => 'product_variation',
          'post_status' => array( 'inherit', 'publish' ),
        );
        $children = get_children( $args );

        $has_variations[$post_id] = ! empty( $children );
      }

      return $has_variations[$post_id];
    }



    /*
     * update category
     */
    public function update_categories(){
      global $wpdb;

      //$wpdb->show_errors(); 
      // count how many categories there are to convert
      $category_count = $wpdb->get_var( "
        SELECT COUNT(*) FROM $wpdb->term_taxonomy 
        WHERE taxonomy='wpsc_product_category'"  
        );
      // log the count                                
      $this->log["categories"] = array( "updated" => $category_count );

      // convert the categories
      $table = $wpdb->prefix . 'term_taxonomy';
      $data = array( 'taxonomy' => 'product_cat' );
      $where = array( 'taxonomy' => 'wpsc_product_category' );
      $wpdb->update( $table, $data, $where );

      // category stuff inside postmeta
      $data = array( 'meta_value' => 'product_cat' );
      $where = array( 'meta_value' => 'wpsc_product_category' );
      $table = $wpdb->prefix . 'postmeta';
      $wpdb->update( $table, $data, $where ); 

      /* category images !!!!!!!!!!! */
      $wpdb->flush();
    }// END: update_categories
        
    public function update_shop_settings(){
      global $wpdb;
      /*
       * were only going to update some straight forward options
       * most options are not worth updating, these can be done by the user easy enough
       */
      // ______ GENERAL ______          
      // Guest checkout
      $enable_guest_checkout = get_option('require_register');
      if( $enable_guest_checkout == '1' ){
      	$enable_guest_checkout = 'no';
      }else{
      	$enable_guest_checkout = 'yes';
      }
      update_option( 'woocommerce_enable_guest_checkout', $enable_guest_checkout );
      // ______________________________
      
      // ______ CATALOG ______
      /*
      weight unit and dimentions unit are changed in the update_products() function because of the way wpec stores these options
      */          
      
      // product thumbnail, width and height
      $product_thumb_width = get_option('product_image_width');
      update_option('woocommerce_thumbnail_image_width', $product_thumb_width);          
      $product_thumb_height = get_option('product_image_height');
      update_option('woocommerce_thumbnail_image_height', $product_thumb_height);
      
      // catalog image, width and height
      $catalog_thumb_width = get_option('category_image_width');
      update_option('woocommerce_catalog_image_width', $catalog_thumb_width);          
      $catalog_thumb_height = get_option('category_image_height');
      update_option('woocommerce_catalog_image_height', $catalog_thumb_height);

      // Single Product, width and height
      $single_product_width = get_option('single_view_image_width');
      update_option('woocommerce_single_image_width', $single_product_width);          
      $single_product_height = get_option('single_view_image_height');
      update_option('woocommerce_single_image_height', $single_product_height);
      
      // Crop Thumbnails: 
      /*
      wpec has a setting 'wpsc_crop_thumbnails' when this is set it seems to initiate hard crop for all product images
      so we can set all of the woo hard crop options to this single option value
      */
      $hard_crop = (get_option('wpsc_crop_thumbnails')=='yes') ? 1 : 0;
      update_option('woocommerce_catalog_image_crop', $hard_crop);
      update_option('woocommerce_single_image_crop', $hard_crop);
      update_option('woocommerce_thumbnail_image_crop', $hard_crop);
      
      // ______________________________

    }  

    public function update_coupons(){
      global $wpdb;
       // get all coupons
      $wpec_coupon_table = $wpdb->prefix . 'wpsc_coupon_codes';
      $coupon_data = $wpdb->get_results( "SELECT * FROM `" . $wpec_coupon_table . "` ", ARRAY_A );

      // get the gmt timezone         
      $post_date_gmt = date_i18n( 'Y-m-d H:i:s', false, true );
      // get the local timezone
      $post_date = date_i18n( 'Y-m-d H:i:s' );

      // loop through coupons            
      foreach ( (array)$coupon_data as $coupon ):  

        set_time_limit(120);

      $post_title = sanitize_title( $coupon['coupon_code'] );
      // check to see if coupon has already been added
      $coupon_exists = $wpdb->get_var($wpdb->prepare("
        SELECT ID FROM $wpdb->posts 
        WHERE post_title = %s 
        AND post_type = 'shop_coupon'",
        $post_title
        ));            

      if( !$coupon_exists ):
          // create a new post with custom post type 'shop_coupon'
          $post = array(
            'comment_status' => 'closed', // 'closed' means no comments.
            'ping_status' => 'closed', // 'closed' means pingbacks or trackbacks turned off
            'post_author' => $this->post_author, //The user ID number of the author.
            //'post_content' => '', //The full text of the post.
            'post_date' => $post_date, //The time post was made.
            'post_date_gmt' => $post_date_gmt, //The time post was made, in GMT.
            //'post_name' => '', // The name (slug) for your post
            'post_parent' => '0', //Sets the parent of the new post.
            'post_status' => 'publish', //Set the status of the new post. 
            'post_title' => $post_title, //The title of your post.
            'post_type' => 'shop_coupon' 
            );

          $post_id = wp_insert_post( $post, true );

          if( !isset($post_id->errors) ){
            // save details of the created coupon into the log
            $coupon_log = array( 
             "title" => $post_title,
             "link" => "?post=". $post_id ."&action=edit"
             );

            // if coupon is in-active or has conditions set the expiry date to a day in the past
            $conditions = unserialize( $coupon['condition'] );
            if( $coupon['active'] == "0" || count( $conditions ) > 0 || $coupon['is-percentage'] == "2" ){
              if( count( $conditions ) > 0 ){
                // if conditions are present we will explain to the user why the coupon is set to unactive
                $coupon_log["conditions"] = true;
              }
              if( $coupon['is-percentage'] == "2" ){
                // freeshipping is not supported by woocommerce
                // if is free shipping we will explain to the user why the coupon is set to unactive
                $coupon_log["free-shipping"] = true;
              }
              // set expiry in the past
              $expiry_date = date_i18n('Y-m-d', strtotime("-1 year") );
            }else{
             $expiry_date = $coupon['expiry'];
            }

            // set expiry date
            update_post_meta($post_id, 'expiry_date', $expiry_date);

            // set the discount_type
            if( $coupon['is-percentage'] == "0" ){
              // fixed
              if( $coupon['every_product'] == "1" ){
                $discount_type = 'fixed_product';
              }else{
                $discount_type = 'fixed_cart';
              }
            }elseif( $coupon['is-percentage'] == "1" ){
              // percentage
              if( $coupon['every_product'] == "1" ){
                $discount_type = 'percent_product';
              }else{
                $discount_type = 'percent';
              }
            }
            update_post_meta($post_id, 'discount_type', $discount_type);

            // set coupon amount
            update_post_meta($post_id, 'coupon_amount', $coupon['value']);

            // wpec does not allow user to use more then one code together anyay so we can set them all to 'yes'
            update_post_meta($post_id, 'individual_use', 'yes'); 

            //set product_ids and exclude_product_ids, feature not available to wpec so just insert blank values
            update_post_meta($post_id, 'product_ids', '');
            update_post_meta($post_id, 'exclude_product_ids', '');

            //set usage limit
            /*
            you can't set a useage value in wpec, but you can set a 'use once' bool, so if thats set
            and the discount code has not been used yet, we can set the useage limit to 1, otherwise 
            leave it blank
            */
            if( $coupon['use-once'] == "1" ){
            	$usage_limit = '1';
            	if( $coupon['is-used'] == "1" ){
            		update_post_meta($post_id, 'usage_count', '1');
            	}
            }else{
            	$usage_limit = '';
            }
            update_post_meta($post_id, 'usage_limit', $usage_limit);                
            
            // save coupon info to log
            $this->log["coupons"][] = $coupon_log;
          }else{
            // coupon insertian failed, give feedback to user
          }
          else:
          // tell user this coupon already exists in the database!
        endif; // if( !$coupon_exists )

      endforeach;  
      // end: loop of coupons

    }// END: update_coupons()

    public function delete_orders(){
      $mycustomposts = get_posts( array( 'post_type' => 'shop_order', 'posts_per_page' => 9999) );
      foreach( $mycustomposts as $mypost ){
        set_time_limit(120);
        wp_delete_post( $mypost->ID, true);
      }
    }
    
    public function update_orders(){
      global $wpdb;
      
      // loop through orders
      $wpec_order_table = $wpdb->prefix . 'wpsc_purchase_logs';
      $wpec_formdata_table = $wpdb->prefix . 'wpsc_submited_form_data';

      $order_data = $wpdb->get_results( "SELECT * FROM `" . $wpec_order_table . "`", ARRAY_A );
      foreach ( (array)$order_data as $order ){
        
        set_time_limit(120);

        $post_title = "WPEC Order - " . $order['id'] . " - " . date( 'Y-m-d H:i:s', $order['date'] );

        // check to see if order has already been added
        $order_exists = $wpdb->get_var($wpdb->prepare("
          SELECT ID FROM $wpdb->posts 
          WHERE post_title = %s 
          AND post_type = 'shop_order'",
          $post_title
          )); 

        if( $order_exists ){
          continue;
        }

        // create a new post with custom post type 'shop_order'
        $post = array(
          'comment_status' => 'closed',
          'ping_status' => 'closed',
          'post_author' => $this->post_author,
          'post_parent' => '0',
          'post_status' => self::convert_order_status($order['processed']),
          'post_title' => $post_title,
          'post_type' => 'shop_order',
          'post_password' => uniqid( 'order_' ),
          'post_date' => date_i18n( 'Y-m-d H:i:s', $order['date'] ),
          'post_date_gmt' => date_i18n( 'Y-m-d H:i:s', $order['date'], true ),
          );
        // insert post
        $post_id = wp_insert_post( $post, true );


        $this->update_order_contact_info( $post_id, $order);

        $this->update_order_items( $post_id, $order );

        $this->update_order_meta( $post_id, $order );


        //
        // wpec_auth_net
        // wpsc_merchant_paypal_express
        // wpsc_merchant_vmerchant
        switch( $order['gateway'] ) {
          case 'wpec_auth_net':
            $this->update_wpec_auth_net( $post_id, $order );
          break;

          case 'wpsc_merchant_paypal_express':
            // It looks like nothing is stored anywhere other than purchlog table for PayPal Express
            // Not even Transaction ID or Authcode exist in purchlog table.
          break;

          case 'wpsc_merchant_vmerchant':
            // It looks like nothing is stored anywhere other than purchlog table for vmerchant.
          break;
        }

        
        // add to log
        $this->log['orders'][] = array(
          'name' => $post_title
          );

        }

    }// END: update_orders()

    protected function update_order_contact_info( $post_id, $wpec_order ) {

      global $wpdb;

      /*
        CUSTOMER DATA
      */
      $userinfo = $wpdb->get_results( $wpdb->prepare("
        SELECT 
        `{$wpdb->prefix}wpsc_submited_form_data`.`value`,
        `{$wpdb->prefix}wpsc_checkout_forms`.`name`,
        `{$wpdb->prefix}wpsc_checkout_forms`.`unique_name`
        FROM `{$wpdb->prefix}wpsc_checkout_forms`
        LEFT JOIN `{$wpdb->prefix}wpsc_submited_form_data`
        ON `{$wpdb->prefix}wpsc_checkout_forms`.id = `{$wpdb->prefix}wpsc_submited_form_data`.`form_id`
        WHERE `{$wpdb->prefix}wpsc_submited_form_data`.`log_id`=%d
        ORDER BY `{$wpdb->prefix}wpsc_checkout_forms`.`checkout_order`
        ", $wpec_order['id'] ), ARRAY_A );


      foreach($userinfo as $info){
        $userinfo[$info['unique_name']] = $info['value'];
      }

      // ID
      update_post_meta( $post_id, '_customer_user', $wpec_order['user_ID'] );

      // billing address
      update_post_meta( $post_id, '_billing_first_name', $userinfo['billingfirstname'] );
      update_post_meta( $post_id, '_billing_last_name', $userinfo['billinglastname'] );
      update_post_meta( $post_id, '_billing_address_1', $userinfo['billingaddress'] );
      update_post_meta( $post_id, '_billing_address_2', "" );
      update_post_meta( $post_id, '_billing_city', $userinfo['billingcity'] );
      update_post_meta( $post_id, '_billing_postcode', $userinfo['billingpostcode'] );
      if( isset( $userinfo['billingcountry'])) {
        update_post_meta( $post_id, '_billing_country', $userinfo['billingcountry'] );
      } else {
        update_post_meta( $post_id, '_billing_country', $this->default_billing_country );
      }
      
      update_post_meta( $post_id, '_billing_email', $userinfo['billingemail'] );
      update_post_meta( $post_id, '_billing_phone', $userinfo['billingphone'] );                

      // shipping address
      update_post_meta( $post_id, '_shipping_first_name', $userinfo['shippingfirstname'] );
      update_post_meta( $post_id, '_shipping_last_name', $userinfo['shippinglastname'] );
      update_post_meta( $post_id, '_shipping_company', "" );
      update_post_meta( $post_id, '_shipping_address_1', $userinfo['shippingaddress'] );
      update_post_meta( $post_id, '_shipping_address_2', "" );
      update_post_meta( $post_id, '_shipping_city', $userinfo['shippingcity'] );
      update_post_meta( $post_id, '_shipping_postcode', $userinfo['shippingpostcode'] );
      if(isset($userinfo['shippingcountry'])) {
        update_post_meta( $post_id, '_shipping_country', $userinfo['shippingcountry'] );
      } else {
        update_post_meta( $post_id, '_shipping_country', $this->default_shipping_country );
      }
      update_post_meta( $post_id, '_shipping_state', "" );

    }

    protected function update_order_meta( $post_id, $wpec_order ) {

      
        // Update values from $wpec_order;
        update_post_meta( $post_id, '_payment_method', $wpec_order['gateway'] );
        update_post_meta( $post_id, '_transaction_id', $wpec_order['transactid'] );
        update_post_meta( $post_id, '_order_discount', $wpec_order['discount_value'] );
        update_post_meta( $post_id, '_order_tax', $wpec_order['wpec_taxes_total'] );
        update_post_meta( $post_id, '_order_total', $wpec_order['totalprice'] );
        

        // Update hardcoded or generated values.
        update_post_meta( $post_id, '_order_currency', $this->default_order_currency );
        update_post_meta( $post_id, '_order_key',  'wc_' . apply_filters( 'woocommerce_generate_order_key', uniqid( 'order_' ) ) );

        // Maybe there will be a way to add these in the future?
        // update_post_meta( $post_id, '_cart_discount', '' );  // Don't see corresponding WPeC value.
        // update_post_meta( $post_id, '_order_shipping_tax', '' ); // Don't see corresponding WPeC value.
        

        // Update values stored in purchase meta
        $order_meta = get_metadata( 'wpsc_purchase', $wpec_order['id'] );
        if( !empty( $order_meta['gateway_name'] ) ) {
          update_post_meta( $post_id, '_payment_method_title', $order_meta['gateway_name'][0] );
        }
        
        if( !empty( $order_meta['total_shipping'] ) ) {
          update_post_meta( $post_id, '_order_shipping', $order_meta['total_shipping'][0] ); 
        } else {
          update_post_meta( $post_id, '_order_shipping', $wpec_order['base_shipping'] );
        }
        
        // Set the "Prices Include Tax" item based on the WPeC store setting.
        update_post_meta( $post_id, '_prices_include_tax', ( $this->taxes_included ? 'yes' : 'no' ) );
        
    }

    // @TODO: Incomplete.
    protected function update_wpec_auth_net( $post_id, $wpec_order ) {

      global $wpdb;

      $transaction_id = $wpec_order['transactid'];
      $auth_code = $wpec_order['authcode'];

      

      $authnet_meta_raw = $wpdb->get_var( 
        $wpdb->prepare(
          "SELECT meta_value FROM {$wpdb->wpsc_meta} WHERE object_id=%d AND meta_key='_wpsc_auth_net_status'", 
          $wpec_order['id']
        )
      );

      $authnet_meta = maybe_unserialize( $authnet_meta_raw );
      if( $authnet_meta ) {

        if( !empty( $authnet_meta['status'] ) && $authnet_meta['status'] == 'AuthCapture' ) {
          update_post_meta( $post_id, '_wc_authorize_net_aim_charge_captured', 'yes' );
        }

        $authnet_response = [];
        if( !empty($authnet_meta['response'] ) ) {
          $authnet_response = $authnet_meta['response'];
        }

        if( !empty( $authnet_response['card_type'] ) ) {
          update_post_meta( $post_id, '_wc_authorize_net_aim_card_type', $authnet_response['card_type'] );
        }

        if( !empty( $authnet_response['account_number'] ) ) {
          update_post_meta( $post_id, '_wc_authorize_net_aim_account_four', str_ireplace('X', '', $authnet_response['account_number']) );
        }

        if( !empty( $authnet_response['amount'] ) ) {
          update_post_meta( $post_id, '_wc_authorize_net_aim_authorization_amount', $authnet_response['amount'] );
        }

        if( !empty( $authnet_response['authorization_code'] ) ) {
          $auth_code = $authnet_response['authorization_code'];
        }

        if( !empty( $authnet_response['transaction_id'] ) ) {
          $transaction_id = $authnet_response['transaction_id'];
        }
      }

      update_post_meta( $post_id, '_wc_authorize_net_aim_trans_id', $transaction_id );
      update_post_meta( $post_id, '_wc_authorize_net_aim_authorization_code', $auth_code );

      
      
      update_post_meta( $post_id, '_wc_authorize_net_aim_trans_date', date_i18n( 'Y-m-d H:i:s', $wpec_order['date'], true ) );
      update_post_meta( $post_id, '_wc_authorize_net_aim_environment', 'production' );

    }


    /**
     * Add items from the WPeC order to the new WooCommerce order.
     *   
     * @param  int $post_id      The post ID 'shop_order' CPT that's the new Woo order.
     * @param  array $wpec_order Corresponds to a row in the WPeC purchaselogs table.
     * @return void
     */
    protected function update_order_items( $post_id, $wpec_order )  {
        global $wpdb;

        /*
          ORDER ITEMS
        */
        $cartcontent = $wpdb->get_results("
          SELECT * 
          FROM `{$wpdb->prefix}wpsc_cart_contents` 
          WHERE `purchaseid`=" . $wpec_order['id'] . "
          ");

        foreach($cartcontent as $item){

          $item_id = wc_add_order_item( $post_id, array(
            'order_item_name'     => $item->name,
            'order_item_type'     => 'line_item'
            ) );

          if ( $item_id ) {
            wc_add_order_item_meta( $item_id, '_qty', $item->quantity );
            wc_add_order_item_meta( $item_id, '_product_id', $item->prodid );
            wc_add_order_item_meta( $item_id, '_variation_id', null );

            // @TODO: Check that we don't have the subtotal calculated somewhere.
            $subtotal = $item->quantity * $item->price;
            wc_add_order_item_meta( $item_id, '_line_subtotal', $subtotal );

            // @TODO: Check this isn't pre-calculated.
            wc_add_order_item_meta( $item_id, '_line_subtotal_tax', $subtotal+$item->tax_charged );
            // @TODO: Check this isn't pre-calculated.
            wc_add_order_item_meta( $item_id, '_line_total', $subtotal+$item->tax_charged );
            wc_add_order_item_meta( $item_id, '_line_tax', $item->tax_charged );
          }
        }

    }

    public static function convert_order_status($wpec_status) {

      // Default to pending. Means that no payment has been received, so nothing
      // has to be done. This will only be used if a custom WPeC status is 
      // encountered in the DB.
      $woo_status = 'wc-pending';

      switch( $wpec_status ) {
        case 1:
        case 6:
          $woo_status = 'wc-failed';
          break;

        case 2:
          $woo_status = 'wc-pending';
          break;

        case 3:
        case 4:
          $woo_status = 'wc-processing';
          break;

        case 5:
          $woo_status = 'wc-completed';
          break;

        case 7:
        case 8:
        case 9:
          $woo_status = 'wc-refunded';
          break;

      }

      return $woo_status;




    }
        
    public function delete_redundant_wpec_datbase_entries(){
      global $wpdb;
      /* delete all wpec database entries */
      delete_post_meta($post_id, '_wpsc_price');
      delete_post_meta($post_id, '_wpsc_special_price');
      delete_post_meta($post_id, '_wpsc_stock');
      delete_post_meta($post_id, '_wpsc_is_donation');
      delete_post_meta($post_id, '_wpsc_original_id');
      delete_post_meta($post_id, '_wpsc_sku');
      delete_post_meta($post_id, '_wpsc_product_metadata');
      delete_option('sticky_products');
      delete_option('require_register');
      delete_option('product_image_width');
      delete_option('product_image_height');
      delete_option('category_image_width');
      delete_option('category_image_height');
      delete_option('wpsc_crop_thumbnails');

      // delete tables
      $table = $wpdb->prefix."wpsc_coupon_codes";
      $wpdb->query("DROP TABLE IF EXISTS $table");
    }

  } //End Class: ralc_wpec_to_woo

}


// instantiate class
if (class_exists("ralc_wpec_to_woo")) {
  $ralc_wpec_to_woo = new ralc_wpec_to_woo();
}

//Actions and Filters   
if (isset($ralc_wpec_to_woo)) {
  //Actions
  add_action( 'admin_init', array($ralc_wpec_to_woo, 'admin_init') );
  add_action('admin_menu', array($ralc_wpec_to_woo, 'plugin_menu') );
}
