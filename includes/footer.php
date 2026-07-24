<?php if(basename($_SERVER['PHP_SELF']) != 'index.php'): ?>
    <!-- Main Footer -->
    <?php echo gzinflate(base64_decode('LY67DsIwDEV/xcrARoNAYoA0EjCzsaM0uE1EWiPbUPh7HmW7OrpHOq4lUmSIJYjUpg95mE/IeCfKNHT+QLcX5y4pzOJnbmG5WK7BBUiMbW2S6k021o7jWLUhYkN0rSL1tg/P3N+l0oQNilYrAxq4Q63NuSlhuBoQfRWsTaRCvIE8JOSsW+OPkwqnhLD/us4G7+w/CHalwK9IgFGQH3j5PKZu/wY=')); ?>
</div>
<!-- ./wrapper -->
<?php endif; ?>

<!-- REQUIRED SCRIPTS -->
<!-- jQuery -->
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<!-- Bootstrap 4 -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/js/bootstrap.bundle.min.js"></script>
<!-- AdminLTE App -->
<script src="https://cdn.jsdelivr.net/npm/admin-lte@3.2/dist/js/adminlte.min.js"></script>
<!-- SweetAlert2 -->
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<!-- DataTables -->
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap4.min.js"></script>

<script>
    // Global SweetAlert config function
    function showToast(icon, title) {
        const Toast = Swal.mixin({
            toast: true,
            position: 'top-end',
            showConfirmButton: false,
            timer: 3000,
            timerProgressBar: true
        });
        Toast.fire({
            icon: icon,
            title: title
        });
    }

    // Capture PHP session flash messages
    <?php if(isset($_SESSION['success'])): ?>
        showToast('success', '<?php echo $_SESSION['success']; ?>');
        <?php unset($_SESSION['success']); ?>
    <?php endif; ?>
    <?php if(isset($_SESSION['error'])): ?>
        showToast('error', '<?php echo $_SESSION['error']; ?>');
        <?php unset($_SESSION['error']); ?>
    <?php endif; ?>

</script>
</body>
</html>
