@extends('sync-engine::layouts.app')

@section('content')
<div class="container">
    <h2>📊 Sync Engine Dashboard</h2>

    <div id="stats" class="row mb-4"></div>

    <table class="table table-bordered">
        <thead>
            <tr>
                <th>Date</th>
                <th>Table</th>
                <th>Action</th>
                <th>Client</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody id="logs"></tbody>
    </table>
</div>

<script>
    fetch('/sync-dashboard/stats')
        .then(r => r.json())
        .then(data => {
            document.getElementById('stats').innerHTML = `
      <div class="col">Total: ${data.total}</div>
      <div class="col">⏳ Pending: ${data.pending}</div>
      <div class="col">✅ Synced: ${data.synced}</div>
      <div class="col">⚠️ Conflicts: ${data.conflicts}</div>
      <div class="col">❌ Errors: ${data.errors}</div>
    `;
        });

    fetch('/sync-dashboard/logs')
        .then(r => r.json())
        .then(data => {
            document.getElementById('logs').innerHTML = data.data.map(log => `
      <tr>
        <td>${log.created_at}</td>
        <td>${log.table}</td>
        <td>${log.action}</td>
        <td>${log.client_id ?? '-'}</td>
        <td>${log.status}</td>
      </tr>
    `).join('');
        });
</script>
@endsection