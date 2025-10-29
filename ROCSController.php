<?php

class ROCSController extends Controller
{
    public function index()
    {
        $this->render('HeaderProject.php', $GLOBALS);
        //$dash = new ProjectDashboards();
        //$dash->renderSetupPage();
        // Custom content goes here
        echo "<div class='container'>";
        echo "<h3>Welcome to My Custom Page!</h3>";
        echo "<p>This is a page using the ProjectDashController framework.</p>";
        echo "</div>";
        $this->render('FooterProject.php');
    }
}
