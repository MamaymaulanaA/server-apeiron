<?php
/**
 * Pagination Utilities
 * 
 * Provides pagination functionality for lists
 */

/**
 * Get pagination parameters from request with validation
 * 
 * @return array ['page' => int, 'per_page' => int, 'offset' => int]
 */
function get_pagination_params(): array {
    // FIX: Use validation function for pagination parameters
    require_once __DIR__ . '/validation.php';
    
    $page_input = $_GET['page'] ?? 1;
    $per_page_input = $_GET['per_page'] ?? 50;
    
    $validated = validate_pagination($page_input, $per_page_input);
    if ($validated === false) {
        // Fallback to safe defaults if validation fails
        $page = 1;
        $per_page = 50;
    } else {
        [$page, $per_page] = $validated;
    }
    
    $offset = ($page - 1) * $per_page;
    
    return [
        'page' => $page,
        'per_page' => $per_page,
        'offset' => $offset
    ];
}

/**
 * Generate pagination HTML
 * 
 * @param int $current_page Current page number
 * @param int $total_pages Total number of pages
 * @param string $base_url Base URL for pagination links
 * @param array $query_params Additional query parameters
 * @return string HTML for pagination
 */
function generate_pagination(int $current_page, int $total_pages, string $base_url = '', array $query_params = []): string {
    if ($total_pages <= 1) {
        return '';
    }
    
    // Build query string
    $query_string = '';
    if (!empty($query_params)) {
        $query_string = '&' . http_build_query($query_params);
    }
    
    $html = '<div class="pagination" style="display: flex; justify-content: center; align-items: center; gap: 10px; margin: 20px 0;">';
    
    // Previous button
    if ($current_page > 1) {
        $prev_page = $current_page - 1;
        $html .= '<a href="' . $base_url . '?page=' . $prev_page . $query_string . '" class="btn btn-sm btn-secondary">';
        $html .= '<i class="fas fa-chevron-left"></i> Previous</a>';
    } else {
        $html .= '<span class="btn btn-sm btn-secondary" style="opacity: 0.5; cursor: not-allowed;">';
        $html .= '<i class="fas fa-chevron-left"></i> Previous</span>';
    }
    
    // Page numbers
    $start_page = max(1, $current_page - 2);
    $end_page = min($total_pages, $current_page + 2);
    
    if ($start_page > 1) {
        $html .= '<a href="' . $base_url . '?page=1' . $query_string . '" class="btn btn-sm btn-secondary">1</a>';
        if ($start_page > 2) {
            $html .= '<span style="padding: 0 10px;">...</span>';
        }
    }
    
    for ($i = $start_page; $i <= $end_page; $i++) {
        if ($i === $current_page) {
            $html .= '<span class="btn btn-sm btn-primary" style="cursor: default;">' . $i . '</span>';
        } else {
            $html .= '<a href="' . $base_url . '?page=' . $i . $query_string . '" class="btn btn-sm btn-secondary">' . $i . '</a>';
        }
    }
    
    if ($end_page < $total_pages) {
        if ($end_page < $total_pages - 1) {
            $html .= '<span style="padding: 0 10px;">...</span>';
        }
        $html .= '<a href="' . $base_url . '?page=' . $total_pages . $query_string . '" class="btn btn-sm btn-secondary">' . $total_pages . '</a>';
    }
    
    // Next button
    if ($current_page < $total_pages) {
        $next_page = $current_page + 1;
        $html .= '<a href="' . $base_url . '?page=' . $next_page . $query_string . '" class="btn btn-sm btn-secondary">';
        $html .= 'Next <i class="fas fa-chevron-right"></i></a>';
    } else {
        $html .= '<span class="btn btn-sm btn-secondary" style="opacity: 0.5; cursor: not-allowed;">';
        $html .= 'Next <i class="fas fa-chevron-right"></i></span>';
    }
    
    $html .= '</div>';
    
    return $html;
}

/**
 * Get pagination info text
 * 
 * @param int $current_page Current page
 * @param int $per_page Items per page
 * @param int $total_items Total items
 * @return string Info text
 */
function get_pagination_info(int $current_page, int $per_page, int $total_items): string {
    $start = (($current_page - 1) * $per_page) + 1;
    $end = min($current_page * $per_page, $total_items);
    
    return "Showing {$start} to {$end} of {$total_items} entries";
}

