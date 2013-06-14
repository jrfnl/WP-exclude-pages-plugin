/**
 * @package Exclude Pages from Navigation
 * @author Juliette Reinders Folmer
 * @since 2.0
 */
function quickEditInMenu() {
    var $ = jQuery;
    var _edit = inlineEditPost.edit;
    inlineEditPost.edit = function(id) {
        var args = [].slice.call(arguments);
        _edit.apply(this, args);

        if (typeof(id) == 'object') {
            id = this.getId(id);
        }
/*var string = '';
string += show_props( id, 'id' );
alert( string );
alert( '#edit-' + id );*/
//alert( this.type );
        if (this.type == 'page') {
            var
            // editRow is the quick-edit row, containing the inputs that need to be updated
            editRow = $('#edit-' + id),
            // postRow is the row shown when a page isn't being edited, which also holds the existing values.
            postRow = $('#post-'+id),

            // get the existing values
            // the class ".column-book_author" is set in display_custom_quickedit_book
//            author = $('.column-book_author', postRow).text(),
            inmenu = !! $('.column-inmenu>input', postRow).attr('checked');


/*var string = '';
string += show_props( editRow[0], 'editRow' );
alert( string );
var string = '';
string += show_props( postRow[0], 'postRow' );
alert( string );
var string = '';
string += show_props( inmenu, 'inmenu' );
alert( string );
*/

            // set the values in the quick-editor
//            $(':input[name="book_author"]', editRow).val(author);
            $(':input[name="ep_this_page_included"]', editRow).attr('checked', inmenu);
        }
    };
}
// Another way of ensuring inlineEditPost.edit isn't patched until it's defined
if (inlineEditPost) {
    quickEditInMenu();
} else {
    jQuery(quickEditInMenu);
}

function show_props(obj, objName) {
   var result = ""
   for (var i in obj) {
      result += objName + "." + i + " = " + obj[i] + "\n"
   }
   return result
}