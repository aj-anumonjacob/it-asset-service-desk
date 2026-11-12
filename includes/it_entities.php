<?php
/**
 * IT Portal — what each page holds.
 *
 * Every list page is driven from these definitions; Settings → Customize
 * overrides labels, visibility, order and adds custom fields per page on top
 * of them (see it_effective_fields()). Field types:
 *   text, textarea, select, date, number, email, url, phone, ip, yesno,
 *   secret (encrypted, one per entity), link (points at another entity).
 */

function it_status_tones(): array {
    // status value => badge tone
    return [
        'Active' => 'green', 'In Use' => 'green', 'Online' => 'green', 'Working' => 'green', 'Closed' => 'gray',
        'In Stock' => 'blue', 'Open' => 'blue', 'Static' => 'blue', 'Reserved' => 'blue',
        'Repair' => 'amber', 'In Progress' => 'amber', 'Waiting' => 'amber', 'Paused' => 'amber', 'Faulty' => 'amber', 'Expiring' => 'amber',
        'Offline' => 'red', 'Lost' => 'red', 'Expired' => 'red', 'Cancelled' => 'gray', 'Disabled' => 'gray',
        'Retired' => 'gray', 'Completed' => 'gray', 'Free' => 'gray', 'DHCP' => 'gray',
        'Low' => 'gray', 'Normal' => 'blue', 'High' => 'amber', 'Urgent' => 'red',
    ];
}

function it_entities(): array {
    static $e = null;
    if ($e !== null) return $e;

    $locations = ['Head Office', 'Reception', 'Server Room', 'Warehouse', 'Site', 'Building', 'Remote', 'Other'];

    $e = [
        'vault' => [
            'label' => 'Passwords', 'singular' => 'Password', 'icon' => 'bi-key',
            'title_field' => 'name', 'status_field' => 'status', 'secret_field' => 'password',
            'due_field' => 'change_due', 'category_field' => 'category', 'sections' => true,
            'fields' => [
                ['key' => 'name', 'label' => 'Name', 'type' => 'text', 'required' => true, 'list' => true, 'placeholder' => 'e.g. Office Wi-Fi, Gmail – accounts@'],
                // Options come from the vault's sections (it_sections), managed on the Vault page.
                ['key' => 'category', 'label' => 'Section', 'type' => 'select', 'required' => true, 'list' => true, 'options' => []],
                ['key' => 'username', 'label' => 'Username / Login', 'type' => 'text', 'list' => true, 'unique_with' => 'url'],
                ['key' => 'password', 'label' => 'Password', 'type' => 'secret', 'required' => true, 'list' => true],
                ['key' => 'url', 'label' => 'URL / Host / IP', 'type' => 'text', 'list' => false, 'placeholder' => 'https://… or 192.168.1.1'],
                ['key' => 'email', 'label' => 'Recovery email', 'type' => 'email'],
                ['key' => 'phone', 'label' => 'Recovery phone', 'type' => 'phone'],
                ['key' => 'used_by', 'label' => 'Used by', 'type' => 'text', 'list' => true, 'placeholder' => 'Person or department'],
                ['key' => 'two_factor', 'label' => '2-step verification on', 'type' => 'yesno'],
                ['key' => 'change_due', 'label' => 'Change password by', 'type' => 'date', 'help' => 'You will get a reminder before this date.'],
                ['key' => 'status', 'label' => 'Status', 'type' => 'select', 'options' => ['Active', 'Disabled'], 'default' => 'Active'],
                ['key' => 'notes', 'label' => 'Notes', 'type' => 'textarea'],
            ],
        ],

        'asset' => [
            'label' => 'Devices', 'singular' => 'Device', 'icon' => 'bi-laptop',
            'title_field' => 'name', 'status_field' => 'status', 'due_field' => 'warranty_until',
            'category_field' => 'category', 'code_prefix' => 'IT-', 'qr' => true, 'photos' => true,
            'fields' => [
                ['key' => 'name', 'label' => 'Device name', 'type' => 'text', 'required' => true, 'list' => true, 'placeholder' => 'e.g. Reception Laptop'],
                ['key' => 'category', 'label' => 'Type', 'type' => 'select', 'required' => true, 'list' => true,
                 'options' => ['Laptop', 'Desktop', 'Monitor', 'Printer / Scanner', 'Mobile Phone', 'Tablet', 'Router', 'Switch', 'Access Point', 'UPS', 'Server / NAS', 'Biometric', 'POS Terminal', 'Accessory', 'Other']],
                ['key' => 'asset_tag', 'label' => 'Asset tag', 'type' => 'text', 'list' => true, 'unique' => true, 'help' => 'Leave empty to auto-number (IT-0001).'],
                ['key' => 'brand', 'label' => 'Brand', 'type' => 'text', 'list' => false],
                ['key' => 'model', 'label' => 'Model', 'type' => 'text', 'list' => true],
                ['key' => 'serial', 'label' => 'Serial number', 'type' => 'text', 'unique' => true],
                ['key' => 'assigned_to', 'label' => 'Assigned to', 'type' => 'text', 'list' => true, 'placeholder' => 'Employee name'],
                ['key' => 'department', 'label' => 'Department', 'type' => 'text'],
                ['key' => 'location', 'label' => 'Location', 'type' => 'select', 'options' => $locations, 'list' => true],
                ['key' => 'status', 'label' => 'Status', 'type' => 'select', 'required' => true, 'list' => true, 'options' => ['In Use', 'In Stock', 'Repair', 'Retired', 'Lost'], 'default' => 'In Use'],
                ['key' => 'specs', 'label' => 'Specs (CPU / RAM / Disk)', 'type' => 'text'],
                ['key' => 'os', 'label' => 'Operating system', 'type' => 'text'],
                ['key' => 'ip_address', 'label' => 'IP address', 'type' => 'ip', 'unique' => true],
                ['key' => 'mac_address', 'label' => 'MAC address', 'type' => 'text', 'unique' => true],
                ['key' => 'purchase_date', 'label' => 'Purchase date', 'type' => 'date'],
                ['key' => 'purchase_price', 'label' => 'Price (AED)', 'type' => 'number'],
                ['key' => 'vendor', 'label' => 'Bought from', 'type' => 'link', 'link' => 'vendor'],
                ['key' => 'warranty_until', 'label' => 'Warranty until', 'type' => 'date'],
                ['key' => 'credential', 'label' => 'Login (from Vault)', 'type' => 'link', 'link' => 'vault'],
                ['key' => 'notes', 'label' => 'Notes', 'type' => 'textarea'],
            ],
        ],

        'nvr' => [
            'label' => 'Recorders (NVR/DVR)', 'singular' => 'Recorder', 'icon' => 'bi-hdd-rack',
            'title_field' => 'name', 'status_field' => 'status', 'children' => 'camera', 'photos' => true,
            'fields' => [
                ['key' => 'name', 'label' => 'Recorder name', 'type' => 'text', 'required' => true, 'list' => true, 'placeholder' => 'e.g. Building A NVR'],
                ['key' => 'kind', 'label' => 'Type', 'type' => 'select', 'options' => ['NVR', 'DVR', 'Cloud'], 'list' => true, 'default' => 'NVR'],
                ['key' => 'brand', 'label' => 'Brand', 'type' => 'text', 'list' => true],
                ['key' => 'model', 'label' => 'Model', 'type' => 'text'],
                ['key' => 'serial', 'label' => 'Serial number', 'type' => 'text', 'unique' => true],
                ['key' => 'ip_address', 'label' => 'IP address', 'type' => 'ip', 'list' => true, 'unique' => true],
                ['key' => 'port', 'label' => 'Web / app port', 'type' => 'text'],
                ['key' => 'p2p_id', 'label' => 'P2P / Cloud ID', 'type' => 'text'],
                ['key' => 'location', 'label' => 'Location', 'type' => 'text', 'list' => true],
                ['key' => 'channels', 'label' => 'Channels', 'type' => 'number'],
                ['key' => 'storage', 'label' => 'Storage (TB)', 'type' => 'text'],
                ['key' => 'retention_days', 'label' => 'Recording kept (days)', 'type' => 'number'],
                ['key' => 'credential', 'label' => 'Login (from Vault)', 'type' => 'link', 'link' => 'vault'],
                ['key' => 'status', 'label' => 'Status', 'type' => 'select', 'options' => ['Online', 'Offline', 'Faulty'], 'list' => true, 'default' => 'Online'],
                ['key' => 'notes', 'label' => 'Notes', 'type' => 'textarea'],
            ],
        ],

        'camera' => [
            'label' => 'Cameras', 'singular' => 'Camera', 'icon' => 'bi-camera-video',
            'title_field' => 'name', 'status_field' => 'status', 'parent_field' => 'nvr', 'photos' => true,
            'fields' => [
                ['key' => 'name', 'label' => 'Camera name', 'type' => 'text', 'required' => true, 'list' => true, 'placeholder' => 'e.g. Main Gate'],
                ['key' => 'nvr', 'label' => 'Recorder', 'type' => 'link', 'link' => 'nvr', 'list' => true],
                ['key' => 'channel', 'label' => 'Channel no.', 'type' => 'number', 'list' => true],
                ['key' => 'location', 'label' => 'Location / view', 'type' => 'text', 'list' => true],
                ['key' => 'ip_address', 'label' => 'IP address', 'type' => 'ip', 'unique' => true],
                ['key' => 'brand', 'label' => 'Brand', 'type' => 'text'],
                ['key' => 'model', 'label' => 'Model', 'type' => 'text'],
                ['key' => 'cam_type', 'label' => 'Type', 'type' => 'select', 'options' => ['Dome', 'Bullet', 'PTZ', 'Fisheye', 'Turret', 'Other']],
                ['key' => 'resolution', 'label' => 'Resolution', 'type' => 'select', 'options' => ['2MP', '4MP', '5MP', '8MP / 4K', 'Other']],
                ['key' => 'night_vision', 'label' => 'Night vision', 'type' => 'yesno'],
                ['key' => 'installed_on', 'label' => 'Installed on', 'type' => 'date'],
                ['key' => 'status', 'label' => 'Status', 'type' => 'select', 'options' => ['Online', 'Offline', 'Faulty'], 'list' => true, 'default' => 'Online'],
                ['key' => 'notes', 'label' => 'Notes', 'type' => 'textarea'],
            ],
        ],

        'isp' => [
            'label' => 'Internet Lines', 'singular' => 'Internet line', 'icon' => 'bi-globe2',
            'title_field' => 'name', 'status_field' => 'status', 'due_field' => 'renewal_date',
            'fields' => [
                ['key' => 'name', 'label' => 'Line name', 'type' => 'text', 'required' => true, 'list' => true, 'placeholder' => 'e.g. Head Office Fiber'],
                ['key' => 'provider', 'label' => 'Provider', 'type' => 'select', 'options' => ['du', 'Etisalat (e&)', 'Starlink', 'Other'], 'list' => true],
                ['key' => 'account_no', 'label' => 'Account number', 'type' => 'text', 'list' => true, 'unique' => true],
                ['key' => 'line_type', 'label' => 'Line type', 'type' => 'select', 'options' => ['Fiber', 'Business Fiber', '4G / 5G Router', 'DSL', 'Leased Line', 'Satellite']],
                ['key' => 'speed', 'label' => 'Speed', 'type' => 'text', 'list' => true, 'placeholder' => 'e.g. 500 Mbps'],
                ['key' => 'static_ip', 'label' => 'Public / static IP', 'type' => 'ip'],
                ['key' => 'location', 'label' => 'Location', 'type' => 'text'],
                ['key' => 'monthly_cost', 'label' => 'Monthly cost (AED)', 'type' => 'number'],
                ['key' => 'support_phone', 'label' => 'Support phone', 'type' => 'phone'],
                ['key' => 'renewal_date', 'label' => 'Contract renewal', 'type' => 'date'],
                ['key' => 'credential', 'label' => 'Router / portal login', 'type' => 'link', 'link' => 'vault'],
                ['key' => 'status', 'label' => 'Status', 'type' => 'select', 'options' => ['Active', 'Disabled'], 'list' => true, 'default' => 'Active'],
                ['key' => 'notes', 'label' => 'Notes', 'type' => 'textarea'],
            ],
        ],

        'ip' => [
            'label' => 'IP Addresses', 'singular' => 'IP address', 'icon' => 'bi-diagram-3',
            'title_field' => 'address', 'status_field' => 'status',
            'fields' => [
                ['key' => 'address', 'label' => 'IP address', 'type' => 'ip', 'required' => true, 'list' => true, 'unique' => true],
                ['key' => 'device', 'label' => 'Device', 'type' => 'text', 'list' => true, 'placeholder' => 'e.g. Reception printer'],
                ['key' => 'asset', 'label' => 'Linked device', 'type' => 'link', 'link' => 'asset'],
                ['key' => 'mac_address', 'label' => 'MAC address', 'type' => 'text'],
                ['key' => 'network', 'label' => 'Network / VLAN', 'type' => 'text', 'list' => true],
                ['key' => 'status', 'label' => 'Type', 'type' => 'select', 'options' => ['Static', 'Reserved', 'DHCP', 'Free'], 'list' => true, 'default' => 'Static'],
                ['key' => 'notes', 'label' => 'Notes', 'type' => 'textarea'],
            ],
        ],

        'license' => [
            'label' => 'Licenses & Renewals', 'singular' => 'License', 'icon' => 'bi-patch-check',
            'title_field' => 'name', 'status_field' => 'status', 'due_field' => 'expiry_date',
            'secret_field' => 'license_key', 'category_field' => 'kind',
            'fields' => [
                ['key' => 'name', 'label' => 'Name', 'type' => 'text', 'required' => true, 'list' => true, 'placeholder' => 'e.g. Microsoft 365 Business, example.com'],
                ['key' => 'kind', 'label' => 'Type', 'type' => 'select', 'required' => true, 'list' => true,
                 'options' => ['Domain', 'SSL Certificate', 'Hosting', 'Email / Microsoft 365 / Google', 'Antivirus', 'Software', 'Subscription', 'Other']],
                ['key' => 'vendor', 'label' => 'Provider', 'type' => 'link', 'link' => 'vendor', 'list' => true],
                ['key' => 'license_key', 'label' => 'License key', 'type' => 'secret'],
                ['key' => 'seats', 'label' => 'Users / seats', 'type' => 'number'],
                ['key' => 'cost', 'label' => 'Cost (AED)', 'type' => 'number', 'list' => true],
                ['key' => 'billing', 'label' => 'Billing', 'type' => 'select', 'options' => ['Monthly', 'Yearly', 'One-time', '2 Years', '3 Years']],
                ['key' => 'start_date', 'label' => 'Start date', 'type' => 'date'],
                ['key' => 'expiry_date', 'label' => 'Expiry / renewal date', 'type' => 'date', 'required' => true, 'list' => true],
                ['key' => 'auto_renew', 'label' => 'Auto-renews', 'type' => 'yesno'],
                ['key' => 'credential', 'label' => 'Account login (from Vault)', 'type' => 'link', 'link' => 'vault'],
                ['key' => 'status', 'label' => 'Status', 'type' => 'select', 'options' => ['Active', 'Expired', 'Cancelled'], 'list' => true, 'default' => 'Active'],
                ['key' => 'notes', 'label' => 'Notes', 'type' => 'textarea'],
            ],
        ],

        'maintenance' => [
            'label' => 'Maintenance Tasks', 'singular' => 'Task', 'icon' => 'bi-calendar2-check',
            'title_field' => 'name', 'status_field' => 'status', 'due_field' => 'next_due',
            'fields' => [
                ['key' => 'name', 'label' => 'Task', 'type' => 'text', 'required' => true, 'list' => true, 'placeholder' => 'e.g. Check CCTV recording'],
                ['key' => 'category', 'label' => 'Category', 'type' => 'select', 'list' => true,
                 'options' => ['Backup', 'CCTV', 'Network', 'Computers', 'Printers', 'Server', 'Security / Updates', 'Cleaning', 'Other']],
                ['key' => 'frequency', 'label' => 'Repeats', 'type' => 'select', 'required' => true, 'list' => true,
                 'options' => ['Once', 'Daily', 'Weekly', 'Monthly', 'Quarterly', 'Half-yearly', 'Yearly'], 'default' => 'Monthly'],
                ['key' => 'next_due', 'label' => 'Next due', 'type' => 'date', 'required' => true, 'list' => true],
                ['key' => 'asset', 'label' => 'Device', 'type' => 'link', 'link' => 'asset'],
                ['key' => 'assigned_to', 'label' => 'Done by', 'type' => 'text', 'list' => true],
                ['key' => 'checklist', 'label' => 'Checklist (one step per line)', 'type' => 'textarea', 'placeholder' => "Check all cameras show picture\nCheck recording date is today\nCheck disk health"],
                ['key' => 'status', 'label' => 'Status', 'type' => 'select', 'options' => ['Active', 'Paused', 'Completed'], 'default' => 'Active'],
                ['key' => 'notes', 'label' => 'Notes', 'type' => 'textarea'],
            ],
        ],

        'request' => [
            'label' => 'IT Requests', 'singular' => 'Request', 'icon' => 'bi-life-preserver',
            'title_field' => 'subject', 'status_field' => 'status', 'code_prefix' => 'REQ-',
            'fields' => [
                ['key' => 'subject', 'label' => 'Problem / request', 'type' => 'text', 'required' => true, 'list' => true, 'placeholder' => 'e.g. Printer not printing'],
                ['key' => 'requested_by', 'label' => 'Requested by', 'type' => 'text', 'required' => true, 'list' => true],
                ['key' => 'department', 'label' => 'Department', 'type' => 'text'],
                ['key' => 'category', 'label' => 'Category', 'type' => 'select', 'options' => ['Hardware', 'Software', 'Network / Internet', 'Email', 'Password / Access', 'CCTV', 'Printer', 'New Device', 'Other'], 'list' => true],
                ['key' => 'priority', 'label' => 'Priority', 'type' => 'select', 'options' => ['Low', 'Normal', 'High', 'Urgent'], 'default' => 'Normal', 'list' => true],
                ['key' => 'asset', 'label' => 'Device', 'type' => 'link', 'link' => 'asset'],
                ['key' => 'details', 'label' => 'Details', 'type' => 'textarea'],
                ['key' => 'status', 'label' => 'Status', 'type' => 'select', 'options' => ['Open', 'In Progress', 'Waiting', 'Closed'], 'default' => 'Open', 'list' => true],
                ['key' => 'resolution', 'label' => 'What was done', 'type' => 'textarea'],
            ],
        ],

        'doc' => [
            'label' => 'Library', 'singular' => 'File', 'icon' => 'bi-folder2-open',
            'title_field' => 'title', 'category_field' => 'category', 'sections' => true, 'library' => true,
            'fields' => [
                ['key' => 'title', 'label' => 'Title', 'type' => 'text', 'required' => true, 'list' => true],
                // Options come from the Library's folders (it_sections), managed on the Library page.
                ['key' => 'category', 'label' => 'Folder', 'type' => 'select', 'required' => true, 'list' => true, 'options' => []],
                ['key' => 'description', 'label' => 'What it shows / when to use it', 'type' => 'textarea'],
                ['key' => 'asset', 'label' => 'Related device', 'type' => 'link', 'link' => 'asset', 'list' => true],
                ['key' => 'tags', 'label' => 'Tags', 'type' => 'text', 'placeholder' => 'e.g. rack, floor 2, hikvision', 'help' => 'Words that help you find it later in search.'],
            ],
        ],

        'staffmail' => [
            'label' => 'Company Emails', 'singular' => 'Company email', 'icon' => 'bi-person-badge',
            'title_field' => 'address', 'status_field' => 'status',
            'fields' => [
                ['key' => 'employee', 'label' => 'Employee', 'type' => 'user', 'required' => true, 'list' => true],
                ['key' => 'address', 'label' => 'Company email address', 'type' => 'email', 'required' => true, 'list' => true, 'unique' => true, 'also_unique_in' => ['mailbox' => 'address'], 'placeholder' => 'e.g. admin@example.com'],
                ['key' => 'company', 'label' => 'Company', 'type' => 'company', 'required' => true, 'list' => true, 'help' => 'The company whose domain this address belongs to.'],
                ['key' => 'aliases', 'label' => 'Other addresses / aliases', 'type' => 'text', 'placeholder' => 'e.g. it@example.com'],
                ['key' => 'provider', 'label' => 'Provider', 'type' => 'select', 'options' => ['Microsoft 365', 'Google Workspace', 'Hostinger', 'cPanel / Webmail', 'Zoho', 'Other']],
                ['key' => 'licence', 'label' => 'Licence / plan', 'type' => 'text', 'placeholder' => 'e.g. Business Basic'],
                ['key' => 'created_on', 'label' => 'Created on', 'type' => 'date'],
                ['key' => 'credential', 'label' => 'Login (from Vault)', 'type' => 'link', 'link' => 'vault'],
                ['key' => 'status', 'label' => 'Status', 'type' => 'select', 'options' => ['Active', 'Disabled'], 'default' => 'Active', 'list' => true],
                ['key' => 'notes', 'label' => 'Notes', 'type' => 'textarea'],
            ],
        ],

        'mailbox' => [
            'label' => 'Shared Mailboxes', 'singular' => 'Shared mailbox', 'icon' => 'bi-envelope-at',
            'title_field' => 'address', 'status_field' => 'status', 'category_field' => 'kind',
            'fields' => [
                ['key' => 'address', 'label' => 'Email address', 'type' => 'email', 'required' => true, 'list' => true, 'unique' => true, 'also_unique_in' => ['staffmail' => 'address'], 'placeholder' => 'e.g. accounts@example.com'],
                ['key' => 'display_name', 'label' => 'Display name', 'type' => 'text', 'list' => true, 'placeholder' => 'e.g. AR Properties Accounts'],
                ['key' => 'company', 'label' => 'Company', 'type' => 'company', 'required' => true, 'list' => true],
                ['key' => 'kind', 'label' => 'Type', 'type' => 'select', 'required' => true, 'list' => true, 'default' => 'Shared mailbox',
                 'options' => ['Shared mailbox', 'Department inbox', 'Distribution group', 'Alias / forwarder', 'No-reply / system']],
                ['key' => 'department', 'label' => 'Department', 'type' => 'text', 'list' => true, 'placeholder' => 'e.g. Accounts, Leasing, HR'],
                ['key' => 'members', 'label' => 'Who reads it / members', 'type' => 'textarea', 'placeholder' => 'One name or email per line'],
                ['key' => 'forwards_to', 'label' => 'Forwards to', 'type' => 'text'],
                ['key' => 'provider', 'label' => 'Provider', 'type' => 'select', 'options' => ['Microsoft 365', 'Google Workspace', 'Hostinger', 'cPanel / Webmail', 'Zoho', 'Other']],
                ['key' => 'credential', 'label' => 'Login (from Vault)', 'type' => 'link', 'link' => 'vault'],
                ['key' => 'status', 'label' => 'Status', 'type' => 'select', 'options' => ['Active', 'Disabled'], 'default' => 'Active', 'list' => true],
                ['key' => 'notes', 'label' => 'Notes', 'type' => 'textarea'],
            ],
        ],

        'vendor' => [
            'label' => 'Vendors & Contacts', 'singular' => 'Vendor', 'icon' => 'bi-person-vcard',
            'title_field' => 'name', 'status_field' => 'status',
            'fields' => [
                ['key' => 'name', 'label' => 'Company / person', 'type' => 'text', 'required' => true, 'list' => true, 'unique' => true],
                ['key' => 'kind', 'label' => 'Type', 'type' => 'select', 'options' => ['Supplier', 'Technician', 'Internet Provider', 'CCTV Installer', 'Software Company', 'Repair Shop', 'Other'], 'list' => true],
                ['key' => 'contact_person', 'label' => 'Contact person', 'type' => 'text', 'list' => true],
                ['key' => 'phone', 'label' => 'Phone', 'type' => 'phone', 'list' => true],
                ['key' => 'email', 'label' => 'Email', 'type' => 'email'],
                ['key' => 'website', 'label' => 'Website', 'type' => 'url'],
                ['key' => 'address', 'label' => 'Address', 'type' => 'textarea'],
                ['key' => 'services', 'label' => 'What they provide', 'type' => 'textarea'],
                ['key' => 'status', 'label' => 'Status', 'type' => 'select', 'options' => ['Active', 'Disabled'], 'default' => 'Active'],
                ['key' => 'notes', 'label' => 'Notes', 'type' => 'textarea'],
            ],
        ],
    ];
    return $e;
}

/** Pages in the menu. A page shows one or more entities as tabs. */
function it_pages(): array {
    return [
        'dashboard'   => ['short' => 'Home', 'title' => 'Dashboard', 'subtitle' => 'Everything that needs you today', 'icon' => 'bi-grid-1x2', 'accent' => '#f97316', 'file' => 'dashboard.php', 'entities' => [], 'group' => 'Overview'],
        'vault'       => ['short' => 'Vault', 'title' => 'Password Vault', 'subtitle' => 'System, email, Wi-Fi and device logins — encrypted', 'icon' => 'bi-shield-lock', 'accent' => '#eab308', 'file' => 'vault.php', 'entities' => ['vault'], 'group' => 'Secure'],
        'users'       => ['short' => 'Logins', 'title' => 'User Logins', 'subtitle' => 'Reset staff passwords for the main system', 'icon' => 'bi-person-lock', 'accent' => '#ef4444', 'file' => 'users.php', 'entities' => [], 'group' => 'Secure', 'admin' => true],
        'inventory'   => ['short' => 'Devices', 'title' => 'IT Inventory', 'subtitle' => 'Laptops, printers, phones and network gear', 'icon' => 'bi-laptop', 'accent' => '#3b82f6', 'file' => 'inventory.php', 'entities' => ['asset'], 'group' => 'Assets'],
        'cameras'     => ['short' => 'Cameras', 'title' => 'CCTV & Cameras', 'subtitle' => 'Recorders, cameras and their logins', 'icon' => 'bi-camera-video', 'accent' => '#ef4444', 'file' => 'cameras.php', 'entities' => ['nvr', 'camera'], 'group' => 'Assets'],
        'network'     => ['short' => 'Network', 'title' => 'Network', 'subtitle' => 'Internet lines and IP addresses', 'icon' => 'bi-router', 'accent' => '#14b8a6', 'file' => 'network.php', 'entities' => ['isp', 'ip'], 'group' => 'Assets'],
        'licenses'    => ['short' => 'Licenses', 'title' => 'Licenses & Renewals', 'subtitle' => 'Domains, SSL, Microsoft 365, antivirus, software', 'icon' => 'bi-patch-check', 'accent' => '#f59e0b', 'file' => 'licenses.php', 'entities' => ['license'], 'group' => 'Assets'],
        'maintenance' => ['short' => 'Tasks', 'title' => 'Maintenance Schedule', 'subtitle' => 'Recurring checks, with a record of every one done', 'icon' => 'bi-calendar2-check', 'accent' => '#22c55e', 'file' => 'maintenance.php', 'entities' => ['maintenance'], 'group' => 'Work'],
        'requests'    => ['short' => 'Requests', 'title' => 'IT Requests', 'subtitle' => 'Problems reported by staff', 'icon' => 'bi-life-preserver', 'accent' => '#fb923c', 'file' => 'requests.php', 'entities' => ['request'], 'group' => 'Work'],
        'library'     => ['short' => 'Library', 'title' => 'Library', 'subtitle' => 'Reference photos and documents for the job', 'icon' => 'bi-folder2-open', 'accent' => '#a855f7', 'file' => 'library.php', 'entities' => ['doc'], 'group' => 'Work'],
        'emails'      => ['short' => 'Emails', 'title' => 'Email Directory', 'subtitle' => 'Staff and shared email addresses, company by company', 'icon' => 'bi-envelope-at', 'accent' => '#0ea5e9', 'file' => 'emails.php', 'entities' => ['staffmail', 'mailbox'], 'group' => 'Work'],
        'vendors'     => ['short' => 'Vendors', 'title' => 'Vendors & Contacts', 'subtitle' => 'Suppliers, technicians and support numbers', 'icon' => 'bi-person-vcard', 'accent' => '#94a3b8', 'file' => 'vendors.php', 'entities' => ['vendor'], 'group' => 'Work'],
        'activity'    => ['short' => 'Activity', 'title' => 'Activity Log', 'subtitle' => 'Who viewed, copied or changed what', 'icon' => 'bi-clock-history', 'accent' => '#0ea5e9', 'file' => 'activity.php', 'entities' => [], 'group' => 'System'],
        'recycle'     => ['short' => 'Bin', 'title' => 'Recycle Bin', 'subtitle' => 'Deleted items — restore anything here', 'icon' => 'bi-trash3', 'accent' => '#ef4444', 'file' => 'recycle.php', 'entities' => [], 'group' => 'System'],
        'settings'    => ['short' => 'Settings', 'title' => 'Settings', 'subtitle' => 'Look, pages, security and access', 'icon' => 'bi-sliders', 'accent' => '#f97316', 'file' => 'settings.php', 'entities' => [], 'group' => 'System', 'locked' => true],
    ];
}

/** The page an entity lives on. */
function it_entity_page(string $entity): string {
    foreach (it_pages() as $k => $p) {
        if (in_array($entity, $p['entities'], true)) return $k;
    }
    return 'dashboard';
}

/** Icons offered in Settings → Customize. */
function it_icon_choices(): array {
    return ['bi-grid-1x2', 'bi-speedometer2', 'bi-shield-lock', 'bi-key', 'bi-safe', 'bi-laptop', 'bi-pc-display', 'bi-printer', 'bi-phone',
        'bi-tablet', 'bi-hdd-rack', 'bi-hdd-network', 'bi-camera-video', 'bi-webcam', 'bi-router', 'bi-wifi', 'bi-globe2', 'bi-diagram-3',
        'bi-patch-check', 'bi-award', 'bi-calendar2-check', 'bi-tools', 'bi-wrench-adjustable', 'bi-life-preserver', 'bi-headset',
        'bi-person-vcard', 'bi-person-lock', 'bi-people', 'bi-building', 'bi-clock-history', 'bi-trash3', 'bi-sliders', 'bi-gear', 'bi-lightning-charge',
        'bi-box-seam', 'bi-cpu', 'bi-database', 'bi-cloud', 'bi-envelope', 'bi-envelope-at', 'bi-star', 'bi-heart', 'bi-bookmark', 'bi-folder2-open'];
}

function it_accent_choices(): array {
    return ['#f97316', '#ea580c', '#eab308', '#f59e0b', '#ef4444', '#b91c1c', '#3b82f6', '#1e40af', '#0ea5e9', '#14b8a6', '#22c55e', '#4d7c0f', '#94a3b8', '#6366f1'];
}

function it_field_types(): array {
    return ['text' => 'Text', 'textarea' => 'Long text', 'number' => 'Number', 'date' => 'Date', 'select' => 'Dropdown',
        'yesno' => 'Yes / No', 'email' => 'Email', 'phone' => 'Phone', 'url' => 'Website link', 'ip' => 'IP address'];
}
