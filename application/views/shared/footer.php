<?php 
//Attempt to fetch session variables:
$user_data = $this->session->userdata('user');
?>
	</div> <!-- End #main_container -->
	
	<div class="container nonesearch">
		<footer class="outsider">
	        <p><a href="https://github.com/USfoundation/us-indexer/commits/develop"><?= version_salt() ?></a> Built with &#10084; in Vancouver. <a href="/terms">Terms</a></p>
		</footer>
	</div>
	
	<script src="/js/main.js?v=<?= version_salt() ?>"></script>
	
  </body>
</html>