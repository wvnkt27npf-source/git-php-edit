<?php
include 'db.php';

// ==============================
// AGENTS CRUD OPERATIONS
// ==============================

// --- DELETE AGENT ---
if (isset($_POST['delete_agent'])) {
    $id = $_POST['agent_id'];
    $check = $conn->query("SELECT count(*) as cnt FROM parties WHERE agent_id=$id")->fetch_assoc();
    
    if($check['cnt'] > 0) {
        echo "<script>Swal.fire('Error', 'Cannot delete! This agent has linked parties.', 'error');</script>";
    } else {
        $conn->query("DELETE FROM agents WHERE id=$id");
        echo "<script>Swal.fire('Deleted', 'Agent removed successfully.', 'success');</script>";
    }
}

// --- UPDATE AGENT ---
if (isset($_POST['update_agent'])) {
    $id = $_POST['edit_agent_id'];
    $stmt = $conn->prepare("UPDATE agents SET agent_name=?, email=?, phone=?, wa_group_id=? WHERE id=?");
    $stmt->bind_param("ssssi", $_POST['agent_name'], $_POST['agent_email'], $_POST['agent_phone'], $_POST['agent_wa_group'], $id);
    
    if($stmt->execute()){
         echo "<script>Swal.fire('Updated', 'Agent Details Updated Successfully', 'success').then(() => { window.location='clients.php'; });</script>";
    } else {
         echo "<script>Swal.fire('Error', 'Database Error', 'error');</script>";
    }
}

// --- ADD AGENT ---
if (isset($_POST['add_agent'])) {
    $stmt = $conn->prepare("INSERT INTO agents (agent_name, email, phone, wa_group_id) VALUES (?, ?, ?, ?)");
    $stmt->bind_param("ssss", $_POST['agent_name'], $_POST['agent_email'], $_POST['agent_phone'], $_POST['agent_wa_group']);
    if($stmt->execute()) echo "<script>Swal.fire('Saved', 'Agent Added', 'success').then(() => { window.location='clients.php'; });</script>";
}

// ==============================
// PARTIES CRUD OPERATIONS
// ==============================

// --- DELETE PARTY ---
if (isset($_POST['delete_party'])) {
    $id = $_POST['party_id'];
    $check = $conn->query("SELECT count(*) as cnt FROM invoices WHERE party_id=$id")->fetch_assoc();
    
    if($check['cnt'] > 0) {
        echo "<script>Swal.fire('Error', 'Cannot delete! This party has existing invoices.', 'error');</script>";
    } else {
        $conn->query("DELETE FROM parties WHERE id=$id");
        echo "<script>Swal.fire('Deleted', 'Party removed successfully.', 'success');</script>";
    }
}

// --- UPDATE PARTY ---
if (isset($_POST['update_party'])) {
    $id = $_POST['edit_party_id'];
    $stmt = $conn->prepare("UPDATE parties SET party_name=?, email=?, phone=?, gstin=?, address=?, contact_person=?, agent_id=?, wa_group_id=? WHERE id=?");
    $agent_id = !empty($_POST['party_agent_id']) ? $_POST['party_agent_id'] : null;
    $stmt->bind_param("ssssssisd", $_POST['party_name'], $_POST['party_email'], $_POST['party_phone'], $_POST['party_gstin'], $_POST['party_addr'], $_POST['party_cp'], $agent_id, $_POST['party_wa_group'], $id);
    
    if($stmt->execute()){
         echo "<script>Swal.fire('Updated', 'Party Details Updated Successfully', 'success').then(() => { window.location='clients.php'; });</script>";
    } else {
         echo "<script>Swal.fire('Error', 'Database Error', 'error');</script>";
    }
}

// --- ADD PARTY ---
if (isset($_POST['add_party'])) {
    $agent_id = !empty($_POST['party_agent_id']) ? $_POST['party_agent_id'] : null;
    $stmt = $conn->prepare("INSERT INTO parties (party_name, email, phone, gstin, address, contact_person, agent_id, wa_group_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("ssssssss", $_POST['party_name'], $_POST['party_email'], $_POST['party_phone'], $_POST['party_gstin'], $_POST['party_addr'], $_POST['party_cp'], $agent_id, $_POST['party_wa_group']);
    if($stmt->execute()) echo "<script>Swal.fire('Saved', 'Party Added', 'success').then(() => { window.location='clients.php'; });</script>";
}

// ==============================
// EXPORT LOGIC
// ==============================
if (isset($_POST['export_agents_csv'])) {
    ob_end_clean(); 
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="agents_export.csv"');
    $output = fopen('php://output', 'w');
    fputcsv($output, array('ID', 'Agent Name', 'Email', 'Phone', 'WA Group ID'));
    
    $rows = $conn->query("SELECT id, agent_name, email, phone, wa_group_id FROM agents");
    while ($row = $rows->fetch_assoc()) fputcsv($output, $row);
    fclose($output);
    exit();
}

if (isset($_POST['export_parties_csv'])) {
    ob_end_clean(); 
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="parties_export.csv"');
    $output = fopen('php://output', 'w');
    fputcsv($output, array('ID', 'Party Name', 'Email', 'Phone', 'GSTIN', 'Address', 'Contact Person', 'Agent ID', 'WA Group ID'));
    
    $rows = $conn->query("SELECT id, party_name, email, phone, gstin, address, contact_person, agent_id, wa_group_id FROM parties");
    while ($row = $rows->fetch_assoc()) fputcsv($output, $row);
    fclose($output);
    exit();
}

// Get agents for dropdown
$agents_list = $conn->query("SELECT id, agent_name FROM agents ORDER BY agent_name ASC");
$agents_arr = [];
while($a = $agents_list->fetch_assoc()) {
    $agents_arr[] = $a;
}

include 'header.php';
?>

<style>
.nav-pills .nav-link.active { background-color: #343a40; }
.table-section { display: none; }
.table-section.active { display: block; }
</style>

<!-- Tab Navigation -->
<ul class="nav nav-pills mb-4">
    <li class="nav-item">
        <a class="nav-link active" href="#" onclick="switchTab('parties')"><i class="fas fa-building"></i> Parties</a>
    </li>
    <li class="nav-item">
        <a class="nav-link" href="#" onclick="switchTab('agents')"><i class="fas fa-user-tie"></i> Agents</a>
    </li>
</ul>

<!-- ========================== -->
<!-- PARTIES SECTION -->
<!-- ========================== -->
<div id="parties-section" class="table-section active">
    <div class="row mb-4">
        <div class="col-12 d-flex justify-content-between align-items-center">
            <h4><i class="fas fa-building"></i> Party Master</h4>
            <div class="d-flex gap-2">
                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addPartyModal"><i class="fas fa-plus"></i> Add New Party</button>
                <form method="POST">
                    <button type="submit" name="export_parties_csv" class="btn btn-success"><i class="fas fa-file-excel"></i> Export</button>
                </form>
            </div>
        </div>
    </div>

    <div class="card shadow-sm">
        <div class="card-body p-0 table-responsive">
            <table class="table table-bordered table-striped mb-0 small datatable">
                <thead class="bg-dark text-white">
                    <tr>
                        <th>ID</th>
                        <th>Party Details</th>
                        <th>Linked Agent</th>
                        <th>WA Group</th>
                        <th>Contact Person</th>
                        <th>GSTIN</th>
                        <th class="text-center">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $res = $conn->query("SELECT p.*, a.agent_name as linked_agent_name FROM parties p LEFT JOIN agents a ON p.agent_id = a.id ORDER BY p.id DESC");
                    while($row = $res->fetch_assoc()):
                        $json_data = htmlspecialchars(json_encode($row), ENT_QUOTES, 'UTF-8');
                    ?>
                    <tr>
                        <td><?php echo $row['id']; ?></td>
                        <td>
                            <strong class="text-primary"><?php echo $row['party_name']; ?></strong><br>
                            <small><i class="fas fa-phone text-muted"></i> <?php echo $row['phone']; ?></small>
                        </td>
                        <td>
                            <?php if($row['linked_agent_name']): ?>
                                <span class="badge bg-info"><i class="fas fa-user-tie"></i> <?php echo $row['linked_agent_name']; ?></span>
                            <?php else: ?>
                                <span class="badge bg-secondary">DIRECT</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if($row['wa_group_id']): ?>
                                <span class="badge bg-success"><i class="fab fa-whatsapp"></i> Set</span>
                            <?php else: ?>
                                <span class="text-muted">-</span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo $row['contact_person']; ?></td>
                        <td><?php echo $row['gstin']; ?></td>
                        <td class="text-center">
                            <button class="btn btn-sm btn-info text-white me-1" onclick="editParty(<?php echo $json_data; ?>)">
                                <i class="fas fa-edit"></i>
                            </button>
                            <form method="POST" class="d-inline" onsubmit="return confirm('Are you sure?');">
                                <input type="hidden" name="party_id" value="<?php echo $row['id']; ?>">
                                <button type="submit" name="delete_party" class="btn btn-sm btn-danger"><i class="fas fa-trash"></i></button>
                            </form>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- ========================== -->
<!-- AGENTS SECTION -->
<!-- ========================== -->
<div id="agents-section" class="table-section">
    <div class="row mb-4">
        <div class="col-12 d-flex justify-content-between align-items-center">
            <h4><i class="fas fa-user-tie"></i> Agent Master</h4>
            <div class="d-flex gap-2">
                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addAgentModal"><i class="fas fa-plus"></i> Add New Agent</button>
                <form method="POST">
                    <button type="submit" name="export_agents_csv" class="btn btn-success"><i class="fas fa-file-excel"></i> Export</button>
                </form>
            </div>
        </div>
    </div>

    <div class="card shadow-sm">
        <div class="card-body p-0 table-responsive">
            <table class="table table-bordered table-striped mb-0 small datatable">
                <thead class="bg-dark text-white">
                    <tr>
                        <th>ID</th>
                        <th>Agent Name</th>
                        <th>Email</th>
                        <th>Phone</th>
                        <th>WA Group</th>
                        <th>Linked Parties</th>
                        <th class="text-center">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $res = $conn->query("SELECT a.*, (SELECT COUNT(*) FROM parties WHERE agent_id = a.id) as party_count FROM agents a ORDER BY a.id DESC");
                    while($row = $res->fetch_assoc()):
                        $json_data = htmlspecialchars(json_encode($row), ENT_QUOTES, 'UTF-8');
                    ?>
                    <tr>
                        <td><?php echo $row['id']; ?></td>
                        <td><strong class="text-primary"><?php echo $row['agent_name']; ?></strong></td>
                        <td><?php echo $row['email']; ?></td>
                        <td><?php echo $row['phone']; ?></td>
                        <td>
                            <?php if($row['wa_group_id']): ?>
                                <span class="badge bg-success"><i class="fab fa-whatsapp"></i> Set</span>
                            <?php else: ?>
                                <span class="text-muted">-</span>
                            <?php endif; ?>
                        </td>
                        <td><span class="badge bg-primary"><?php echo $row['party_count']; ?> Parties</span></td>
                        <td class="text-center">
                            <button class="btn btn-sm btn-info text-white me-1" onclick="editAgent(<?php echo $json_data; ?>)">
                                <i class="fas fa-edit"></i>
                            </button>
                            <form method="POST" class="d-inline" onsubmit="return confirm('Are you sure?');">
                                <input type="hidden" name="agent_id" value="<?php echo $row['id']; ?>">
                                <button type="submit" name="delete_agent" class="btn btn-sm btn-danger"><i class="fas fa-trash"></i></button>
                            </form>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- ========================== -->
<!-- ADD PARTY MODAL -->
<!-- ========================== -->
<div class="modal fade" id="addPartyModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-dark text-white">
                <h5 class="modal-title"><i class="fas fa-building"></i> Add New Party</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form method="POST" class="row g-3">
                    <div class="col-md-6"><label class="small fw-bold">Party Name *</label><input type="text" name="party_name" class="form-control" required></div>
                    <div class="col-md-6"><label class="small fw-bold">Email</label><input type="email" name="party_email" class="form-control"></div>
                    <div class="col-md-6"><label class="small fw-bold">Phone</label><input type="text" name="party_phone" class="form-control"></div>
                    <div class="col-md-6"><label class="small fw-bold">GSTIN</label><input type="text" name="party_gstin" class="form-control"></div>
                    <div class="col-md-6"><label class="small fw-bold">Contact Person</label><input type="text" name="party_cp" class="form-control"></div>
                    <div class="col-md-6"><label class="small fw-bold">Address</label><input type="text" name="party_addr" class="form-control"></div>
                    
                    <div class="col-12"><hr class="my-1"> <h6 class="text-primary"><i class="fas fa-link"></i> Agent & WhatsApp Settings</h6></div>
                    
                    <!-- Agent Dropdown -->
                    <div class="col-md-6">
                        <label class="small fw-bold">Link to Agent</label>
                        <select name="party_agent_id" class="form-select">
                            <option value="">-- Direct Party (No Agent) --</option>
                            <?php foreach($agents_arr as $agent): ?>
                                <option value="<?php echo $agent['id']; ?>"><?php echo $agent['agent_name']; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <!-- WhatsApp Group -->
                    <div class="col-md-6">
                        <label class="small fw-bold">Party WhatsApp Group</label>
                        <div class="input-group">
                            <input type="text" id="party_wa_group_name" class="form-control" placeholder="Type Group Name...">
                            <button type="button" class="btn btn-success" onclick="fetchGroupId('add_party')">
                                <i class="fab fa-whatsapp"></i> Fetch
                            </button>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <label class="small fw-bold">Group ID</label>
                        <input type="text" name="party_wa_group" id="party_wa_group_id" class="form-control bg-light" placeholder="Auto-fetched" readonly>
                    </div>
                    
                    <div class="col-12 text-end"><button type="submit" name="add_party" class="btn btn-primary"><i class="fas fa-save"></i> Save Party</button></div>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- ========================== -->
<!-- EDIT PARTY MODAL -->
<!-- ========================== -->
<div class="modal fade" id="editPartyModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-info text-white">
                <h5 class="modal-title"><i class="fas fa-edit"></i> Edit Party Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form method="POST" class="row g-3">
                    <input type="hidden" name="edit_party_id" id="ep_id">
                    <div class="col-md-6"><label class="small fw-bold">Party Name *</label><input type="text" name="party_name" id="ep_name" class="form-control" required></div>
                    <div class="col-md-6"><label class="small fw-bold">Email</label><input type="email" name="party_email" id="ep_email" class="form-control"></div>
                    <div class="col-md-6"><label class="small fw-bold">Phone</label><input type="text" name="party_phone" id="ep_phone" class="form-control"></div>
                    <div class="col-md-6"><label class="small fw-bold">GSTIN</label><input type="text" name="party_gstin" id="ep_gstin" class="form-control"></div>
                    <div class="col-md-6"><label class="small fw-bold">Contact Person</label><input type="text" name="party_cp" id="ep_cp" class="form-control"></div>
                    <div class="col-md-6"><label class="small fw-bold">Address</label><input type="text" name="party_addr" id="ep_addr" class="form-control"></div>
                    
                    <div class="col-12"><hr class="my-1"> <h6 class="text-primary"><i class="fas fa-link"></i> Agent & WhatsApp Settings</h6></div>
                    
                    <!-- Agent Dropdown -->
                    <div class="col-md-6">
                        <label class="small fw-bold">Link to Agent</label>
                        <select name="party_agent_id" id="ep_agent_id" class="form-select">
                            <option value="">-- Direct Party (No Agent) --</option>
                            <?php foreach($agents_arr as $agent): ?>
                                <option value="<?php echo $agent['id']; ?>"><?php echo $agent['agent_name']; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <!-- WhatsApp Group -->
                    <div class="col-md-6">
                        <label class="small fw-bold">Party WhatsApp Group</label>
                        <div class="input-group">
                            <input type="text" id="ep_wa_group_name" class="form-control" placeholder="Type Group Name...">
                            <button type="button" class="btn btn-success" onclick="fetchGroupId('edit_party')">
                                <i class="fab fa-whatsapp"></i> Fetch
                            </button>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <label class="small fw-bold">Group ID</label>
                        <input type="text" name="party_wa_group" id="ep_wa_group" class="form-control bg-light" readonly>
                    </div>
                    
                    <div class="col-12 text-end"><button type="submit" name="update_party" class="btn btn-info text-white"><i class="fas fa-save"></i> Update Party</button></div>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- ========================== -->
<!-- ADD AGENT MODAL -->
<!-- ========================== -->
<div class="modal fade" id="addAgentModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-dark text-white">
                <h5 class="modal-title"><i class="fas fa-user-tie"></i> Add New Agent</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form method="POST" class="row g-3">
                    <div class="col-12"><label class="small fw-bold">Agent Name *</label><input type="text" name="agent_name" class="form-control" required></div>
                    <div class="col-md-6"><label class="small fw-bold">Email</label><input type="email" name="agent_email" class="form-control"></div>
                    <div class="col-md-6"><label class="small fw-bold">Phone</label><input type="text" name="agent_phone" class="form-control"></div>
                    
                    <div class="col-12"><hr class="my-1"> <h6 class="text-success"><i class="fab fa-whatsapp"></i> WhatsApp Group</h6></div>
                    
                    <div class="col-8">
                        <label class="small fw-bold">Group Name</label>
                        <div class="input-group">
                            <input type="text" id="agent_wa_group_name" class="form-control" placeholder="Type Group Name...">
                            <button type="button" class="btn btn-success" onclick="fetchGroupId('add_agent')">
                                <i class="fab fa-whatsapp"></i> Fetch
                            </button>
                        </div>
                    </div>
                    <div class="col-4">
                        <label class="small fw-bold">Group ID</label>
                        <input type="text" name="agent_wa_group" id="agent_wa_group_id" class="form-control bg-light" readonly>
                    </div>
                    
                    <div class="col-12 text-end"><button type="submit" name="add_agent" class="btn btn-primary"><i class="fas fa-save"></i> Save Agent</button></div>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- ========================== -->
<!-- EDIT AGENT MODAL -->
<!-- ========================== -->
<div class="modal fade" id="editAgentModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-info text-white">
                <h5 class="modal-title"><i class="fas fa-edit"></i> Edit Agent Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form method="POST" class="row g-3">
                    <input type="hidden" name="edit_agent_id" id="ea_id">
                    <div class="col-12"><label class="small fw-bold">Agent Name *</label><input type="text" name="agent_name" id="ea_name" class="form-control" required></div>
                    <div class="col-md-6"><label class="small fw-bold">Email</label><input type="email" name="agent_email" id="ea_email" class="form-control"></div>
                    <div class="col-md-6"><label class="small fw-bold">Phone</label><input type="text" name="agent_phone" id="ea_phone" class="form-control"></div>
                    
                    <div class="col-12"><hr class="my-1"> <h6 class="text-success"><i class="fab fa-whatsapp"></i> WhatsApp Group</h6></div>
                    
                    <div class="col-8">
                        <label class="small fw-bold">Group Name</label>
                        <div class="input-group">
                            <input type="text" id="ea_wa_group_name" class="form-control" placeholder="Type Group Name...">
                            <button type="button" class="btn btn-success" onclick="fetchGroupId('edit_agent')">
                                <i class="fab fa-whatsapp"></i> Fetch
                            </button>
                        </div>
                    </div>
                    <div class="col-4">
                        <label class="small fw-bold">Group ID</label>
                        <input type="text" name="agent_wa_group" id="ea_wa_group" class="form-control bg-light" readonly>
                    </div>
                    
                    <div class="col-12 text-end"><button type="submit" name="update_agent" class="btn btn-info text-white"><i class="fas fa-save"></i> Update Agent</button></div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
// Tab Switching
function switchTab(tab) {
    document.querySelectorAll('.table-section').forEach(s => s.classList.remove('active'));
    document.querySelectorAll('.nav-link').forEach(l => l.classList.remove('active'));
    
    document.getElementById(tab + '-section').classList.add('active');
    event.target.classList.add('active');
}

// Edit Party Function
function editParty(data) {
    document.getElementById('ep_id').value = data.id;
    document.getElementById('ep_name').value = data.party_name;
    document.getElementById('ep_email').value = data.email;
    document.getElementById('ep_phone').value = data.phone;
    document.getElementById('ep_gstin').value = data.gstin;
    document.getElementById('ep_cp').value = data.contact_person;
    document.getElementById('ep_addr').value = data.address;
    document.getElementById('ep_agent_id').value = data.agent_id || '';
    document.getElementById('ep_wa_group').value = data.wa_group_id || '';
    document.getElementById('ep_wa_group_name').value = '';
    
    new bootstrap.Modal(document.getElementById('editPartyModal')).show();
}

// Edit Agent Function
function editAgent(data) {
    document.getElementById('ea_id').value = data.id;
    document.getElementById('ea_name').value = data.agent_name;
    document.getElementById('ea_email').value = data.email;
    document.getElementById('ea_phone').value = data.phone;
    document.getElementById('ea_wa_group').value = data.wa_group_id || '';
    document.getElementById('ea_wa_group_name').value = '';
    
    new bootstrap.Modal(document.getElementById('editAgentModal')).show();
}

// Fetch Group ID from UltraMsg API
function fetchGroupId(mode) {
    let groupNameField, groupIdField;
    
    switch(mode) {
        case 'add_party':
            groupNameField = document.getElementById('party_wa_group_name');
            groupIdField = document.getElementById('party_wa_group_id');
            break;
        case 'edit_party':
            groupNameField = document.getElementById('ep_wa_group_name');
            groupIdField = document.getElementById('ep_wa_group');
            break;
        case 'add_agent':
            groupNameField = document.getElementById('agent_wa_group_name');
            groupIdField = document.getElementById('agent_wa_group_id');
            break;
        case 'edit_agent':
            groupNameField = document.getElementById('ea_wa_group_name');
            groupIdField = document.getElementById('ea_wa_group');
            break;
    }
    
    let groupName = groupNameField.value.trim();
    
    if (!groupName) {
        Swal.fire('Error', 'कृपया Group Name डालें!', 'warning');
        return;
    }
    
    Swal.fire({
        title: 'Fetching...',
        text: 'UltraMsg API से Group ID लाया जा रहा है...',
        allowOutsideClick: false,
        didOpen: () => { Swal.showLoading(); }
    });
    
    fetch('get_group_id.php?group_name=' + encodeURIComponent(groupName))
        .then(response => response.text())
        .then(text => {
            Swal.close();
            try {
                let data = JSON.parse(text);
                if (data.success) {
                    groupIdField.value = data.group_id;
                    Swal.fire('Success', 'Group: ' + data.group_name + '<br>ID: ' + data.group_id, 'success');
                } else {
                    let msg = data.message || 'Group नहीं मिला!';
                    if (data.available_groups) {
                        msg += '<br><br><b>Available Groups:</b><br>' + data.available_groups.join('<br>');
                    }
                    Swal.fire({icon: 'error', title: 'Not Found', html: msg});
                }
            } catch(e) {
                Swal.fire('Error', 'Invalid Response: ' + text.substring(0, 200), 'error');
            }
        })
        .catch(error => {
            Swal.close();
            Swal.fire('Error', 'Network Error: ' + error.message, 'error');
        });
}
</script>

<?php include 'footer.php'; ?>
