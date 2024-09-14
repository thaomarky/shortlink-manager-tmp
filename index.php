<?php
/*
Plugin Name: Shortlink Manager TMP
Plugin URI: https://thaomarky.com/share-plugin-shortlink-manager-tmp-free.html
Description: Create and manage short links.
Version: 1.0
Author: Thao Marky
Author URI: https://thaomarky.com
License: GPLv2 or later
*/

// Hook to activate the plugin and create the database table
function tmp_create_shortlink_table() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'shortlinks';

    if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") !== $table_name) {
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $table_name (
            id MEDIUMINT(9) NOT NULL AUTO_INCREMENT,
            slug VARCHAR(8) NOT NULL,
            url TEXT NOT NULL,
            clicks INT(11) DEFAULT 0 NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY slug (slug),
            UNIQUE KEY url (url)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }
}
register_activation_hook(__FILE__, 'tmp_create_shortlink_table');

// Generate a random slug
function tmp_generate_random_slug() {
    return substr(md5(time() . wp_rand()), 0, 8);
}

// Handle redirection for short links
function tmp_redirect_shortlink() {
    global $wpdb;
    $slug = trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');
    $table_name = $wpdb->prefix . 'shortlinks';

    if (!empty($slug)) {
        $link = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_name WHERE slug = %s", $slug
        ));

        if ($link) {
            // Increment click count
            $wpdb->update(
                $table_name,
                array('clicks' => $link->clicks + 1),
                array('slug' => $slug),
                array('%d'),
                array('%s')
            );

            // Redirect to the original URL
            wp_redirect(esc_url($link->url));
            exit;
        }
    }
}
add_action('init', 'tmp_redirect_shortlink');

// Enqueue admin styles and scripts
function tmp_enqueue_admin_scripts($hook) {
    if ($hook !== 'toplevel_page_shortlink-manager') {
        return;
    }
    wp_enqueue_style('tmp-admin-style', plugin_dir_url(__FILE__) . 'css/admin-style.min.css');
    wp_enqueue_script('tmp-admin-script', plugin_dir_url(__FILE__) . 'js/admin-script.min.js', array('jquery'), null, true);
}
add_action('admin_enqueue_scripts', 'tmp_enqueue_admin_scripts');

// Add admin menu for the plugin
function tmp_admin_page() {
    add_menu_page(
        'Shortlink Manager',    // Page title
        'Shortlink Manager',    // Menu title
        'manage_options',       // Capability
        'shortlink-manager',    // Menu slug
        'tmp_admin_page_content', // Function to display content
        'dashicons-admin-links', // Icon
        20                      // Position
    );
}
add_action('admin_menu', 'tmp_admin_page');

// Display the admin page content
function tmp_admin_page_content() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'shortlinks';

    // Handle link deletion
    if (isset($_GET['delete']) && check_admin_referer('tmp_delete_link_' . intval($_GET['delete']))) {
        $delete_id = intval($_GET['delete']);
        $wpdb->delete($table_name, array('id' => $delete_id), array('%d'));
        echo '<div class="notice notice-success is-dismissible"><p>Short link deleted successfully!</p></div>';
    }

    // Handle form submission
    if (isset($_POST['submit']) && check_admin_referer('tmp_save_link', 'tmp_nonce')) {
        $url = esc_url_raw($_POST['url']);
        $slug = !empty($_POST['slug']) ? sanitize_title($_POST['slug']) : tmp_generate_random_slug();

        if (empty($url)) {
            echo '<div class="notice notice-error is-dismissible"><p>Please enter a valid URL.</p></div>';
        } else {
            // Check for existing link
            $existing_link = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM $table_name WHERE url = %s AND id != %d", $url, intval($_POST['edit_id'])
            ));

            if ($existing_link) {
                echo '<div class="notice notice-error is-dismissible"><p>This URL already exists with slug: ' . esc_html($existing_link->slug) . '</p></div>';
            } else {
                if (!empty($_POST['edit_id'])) {
                    // Update existing link
                    $edit_id = intval($_POST['edit_id']);
                    $wpdb->update(
                        $table_name,
                        array(
                            'slug' => $slug,
                            'url' => $url
                        ),
                        array('id' => $edit_id),
                        array('%s', '%s'),
                        array('%d')
                    );
                    echo '<div class="notice notice-success is-dismissible"><p>Short link updated successfully!</p></div>';
                } else {
                    // Insert new link
                    $wpdb->insert(
                        $table_name,
                        array(
                            'slug' => $slug,
                            'url' => $url,
                            'clicks' => 0
                        ),
                        array('%s', '%s', '%d')
                    );
                    echo '<div class="notice notice-success is-dismissible"><p>Short link created successfully!</p></div>';
                }
            }
        }
    }

    // Handle editing of a link
    $edit_data = null;
    if (isset($_GET['edit'])) {
        $edit_id = intval($_GET['edit']);
        $edit_data = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_name WHERE id = %d", $edit_id
        ));
    }

    // Handle search
    $search = isset($_GET['search']) ? sanitize_text_field($_GET['search']) : '';
    $search_query = $search ? $wpdb->prepare("WHERE slug LIKE %s OR url LIKE %s", "%$search%", "%$search%") : '';
    
    // Handle sorting
    $orderby = isset($_GET['orderby']) ? sanitize_key($_GET['orderby']) : 'id';
    $order = isset($_GET['order']) ? strtoupper(sanitize_key($_GET['order'])) : 'ASC';
    
    // Validate sorting values
    $allowed_orderby = array('id', 'clicks');
    $allowed_order = array('ASC', 'DESC');
    
    if (!in_array($orderby, $allowed_orderby)) {
        $orderby = 'id';
    }
    if (!in_array($order, $allowed_order)) {
        $order = 'ASC';
    }
    
    // Pagination
    $paged = isset($_GET['paged']) ? intval($_GET['paged']) : 1;
    $limit = 10;
    $offset = ($paged - 1) * $limit;
    $results = tmp_get_shortlinks($search, $orderby, $order, $limit, $offset);

    // Calculate total pages
    $total_links = $wpdb->get_var("SELECT COUNT(*) FROM $table_name $search_query");
    $total_pages = ceil($total_links / $limit);
    ?>

    <div class="wrap">
        <h1><?php echo $edit_data ? 'Edit Short Link' : 'Create Short Link'; ?></h1>

        <form method="post" action="">
            <?php wp_nonce_field('tmp_save_link', 'tmp_nonce'); ?>
            <?php if ($edit_data): ?>
                <input type="hidden" name="edit_id" value="<?php echo esc_attr($edit_data->id); ?>">
            <?php endif; ?>
            <table class="form-table">
                <tr valign="top">
                    <th scope="row">Original URL</th>
                    <td><input type="text" name="url" value="<?php echo esc_attr($edit_data ? $edit_data->url : ''); ?>" class="regular-text" /></td>
                </tr>
                <tr valign="top">
                    <th scope="row">Slug (8 characters)</th>
                    <td><input type="text" name="slug" value="<?php echo esc_attr($edit_data ? $edit_data->slug : ''); ?>" class="regular-text" /></td>
                </tr>
            </table>
            <p class="submit">
                <input type="submit" name="submit" class="button-primary" value="<?php echo $edit_data ? 'Update Link' : 'Create Short Link'; ?>">
            </p>
        </form>

        <h2>Search Short Links</h2>
        <form method="get" action="">
            <input type="hidden" name="page" value="shortlink-manager">
            <p>
                <label for="search">Search:</label>
                <input type="text" id="search" name="search" value="<?php echo esc_attr($search); ?>" class="regular-text">
                <input type="submit" class="button" value="Search">
            </p>
        </form>

        <h2>Short Links List</h2>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th style="width: 5%;">
                        <a href="<?php echo esc_url(add_query_arg(array('orderby' => 'id', 'order' => ($orderby == 'id' && $order == 'ASC') ? 'DESC' : 'ASC', 'paged' => $paged, 'search' => $search))); ?>">ID</a>
                    </th>
                    <th style="width: 10%;">Slug</th>
                    <th>URL</th>
                    <th style="width: 10%;">
                        <a href="<?php echo esc_url(add_query_arg(array('orderby' => 'clicks', 'order' => ($orderby == 'clicks' && $order == 'ASC') ? 'DESC' : 'ASC', 'paged' => $paged, 'search' => $search))); ?>">Clicks</a>
                    </th>
                    <th style="width: 20%;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php
                if ($results):
                    foreach ($results as $row):
                        $shortlink = home_url('/' . esc_attr($row->slug));
                ?>
                        <tr>
                            <td><?php echo intval($row->id); ?></td>
                            <td><?php echo esc_html($row->slug); ?></td>
                            <td><a href="<?php echo esc_url($row->url); ?>" target="_blank"><?php echo esc_url($row->url); ?></a></td>
                            <td><?php echo intval($row->clicks); ?></td>
                            <td>
                                <a href="<?php echo esc_url(admin_url('admin.php?page=shortlink-manager&edit=' . intval($row->id))); ?>" class="button">Edit</a>
                                <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin.php?page=shortlink-manager&delete=' . intval($row->id)), 'tmp_delete_link_' . intval($row->id))); ?>" class="button button-danger" onclick="return confirm('Are you sure you want to delete this link?');">Delete</a>
                                <button onclick="copyLink('<?php echo esc_url(home_url('/' . esc_attr($row->slug))); ?>')" class="button">Copy</button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="5">No short links available.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>

        <div class="tablenav">
            <div class="tablenav-pages">
                <?php
                if ($total_pages > 1) {
                    for ($i = 1; $i <= $total_pages; $i++) {
                        $class = ($i == $paged) ? ' page-number current' : ' page-number';
                        echo '<a href="' . esc_url(add_query_arg(array('paged' => $i, 'search' => $search, 'orderby' => $orderby, 'order' => $order))) . '" class="' . esc_attr($class) . '">' . $i . '</a> ';
                    }
                }
                ?>
            </div>
        </div>
    </div>

    <?php
}

// Fetch short links from the database
function tmp_get_shortlinks($search = '', $orderby = 'id', $order = 'ASC', $limit = 10, $offset = 0) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'shortlinks';

    // Build query with search, sorting, and pagination
    $search_query = !empty($search) ? $wpdb->prepare("WHERE slug LIKE %s OR url LIKE %s", "%$search%", "%$search%") : '';
    
    $query = $wpdb->prepare(
        "SELECT * FROM $table_name $search_query ORDER BY $orderby $order LIMIT %d OFFSET %d",
        $limit, $offset
    );

    return $wpdb->get_results($query);
}
?>