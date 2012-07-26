$(function() {
	myHeight = $('#nav');
	myHeight.hide();
});

function create_menu(basepath)
{
	var base = (basepath == 'null') ? '' : basepath;

	document.write(
		'<table cellpadding="0" cellspaceing="0" border="0" style="width:98%"><tr>' +
		'<td class="td" valign="top">' +

		'<ul>' +
		'<li><a href="'+base+'index.html">User guide home</a></li>' +
		'<li><a href="'+base+'toc.html">Table of contents</a></li>' +
		'</ul>' +

		'<h3>Basic Info</h3>' +
		'<ul>' +
		'<li><a href="'+base+'basic/introduction.html">Introduction</a></li>' +
		'<li><a href="'+base+'basic/structure.html">File structure</a></li>' +
		'<li><a href="'+base+'basic/installation.html">Installation</a></li>' +
		'<li><a href="'+base+'basic/first_project.html">Your first project</a></li>' +
		'<li><a href="'+base+'basic/learning.html">Learning Mainframe</a></li>' +
		'</ul>' +

		'</td><td class="td_sep" valign="top">' +

		'<h3>General Topics</h3>' +
		'<ul>' +
		'<li><a href="'+base+'general/libs.html">3rd party libraries</a></li>' +
		'<li><a href="'+base+'general/themes.html">Themes</a></li>' +
		'<li><a href="'+base+'general/assets.html">Assets</a></li>' +
		'<li><a href="'+base+'general/plugins.html">Plugins</a></li>' +
		'</ul>' +

		

		'</td><td class="td_sep" valign="top">' +

		'<h3>Additional Resources</h3>' +
		'<ul>' +
		'<li><a href="http://mainframephp.com">Mainframe PHP home page</a></li>' +
		'<li><a href="http://demianlabs.com">Demian Labs</a></li>' +
		'</ul>' +

		'</td><td class="td_sep" valign="top">' +

		'</ul>' +

		'<h3>Helper Reference</h3>' +
		'<ul>' +
		'<li><a href="'+base+'helpers/debug_helper.html">Debug helper</a></li>' +
		'<li><a href="'+base+'helpers/mainframe_helper.html">Mainframe helper</a></li>' +
		'</ul>' +

		'</td></tr></table>');
}