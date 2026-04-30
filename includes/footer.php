    </main><!-- /.page-content -->
</div><!-- /.main-wrapper -->

<div id="sb-overlay" onclick="closeSidebar()" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:199;"></div>

<script>
function toggleSidebar(){
    const s=document.getElementById('sidebar'),o=document.getElementById('sb-overlay');
    s.classList.toggle('open');
    o.style.display=s.classList.contains('open')?'block':'none';
}
function closeSidebar(){
    document.getElementById('sidebar').classList.remove('open');
    document.getElementById('sb-overlay').style.display='none';
}
<?php if(isset($extra_script)) echo $extra_script; ?>
</script>
</body>
</html>
