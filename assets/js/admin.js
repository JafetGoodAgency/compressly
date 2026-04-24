/*
 * Compressly admin JS.
 *
 * Tab switcher for Settings → Compressly. Vanilla JS, no jQuery.
 * Tab state survives a page reload via sessionStorage and the URL
 * hash so a save round-trip lands the user back on the tab they were
 * editing.
 */

( function () {
    'use strict';

    var STORAGE_KEY = 'compresslyActiveTab';
    var root = document.querySelector( '.compressly-settings' );
    if ( ! root ) {
        return;
    }

    var tabs = root.querySelectorAll( '.nav-tab[data-compressly-tab]' );
    var panels = root.querySelectorAll( '.compressly-tab-panel' );
    if ( ! tabs.length || ! panels.length ) {
        return;
    }

    function activate( slug ) {
        var matched = false;
        tabs.forEach( function ( tab ) {
            var isActive = tab.getAttribute( 'data-compressly-tab' ) === slug;
            tab.classList.toggle( 'nav-tab-active', isActive );
            tab.setAttribute( 'aria-selected', isActive ? 'true' : 'false' );
            tab.setAttribute( 'tabindex', isActive ? '0' : '-1' );
            if ( isActive ) {
                matched = true;
            }
        } );
        panels.forEach( function ( panel ) {
            panel.classList.toggle( 'is-active', panel.id === 'compressly-tab-' + slug );
        } );

        if ( matched ) {
            try {
                window.history.replaceState( null, '', '#' + slug );
            } catch ( e ) {}
            try {
                window.sessionStorage.setItem( STORAGE_KEY, slug );
            } catch ( e ) {}
        }

        return matched;
    }

    tabs.forEach( function ( tab ) {
        tab.addEventListener( 'click', function ( event ) {
            event.preventDefault();
            activate( tab.getAttribute( 'data-compressly-tab' ) );
        } );

        tab.addEventListener( 'keydown', function ( event ) {
            if ( event.key !== 'ArrowLeft' && event.key !== 'ArrowRight' ) {
                return;
            }
            event.preventDefault();
            var list = Array.prototype.slice.call( tabs );
            var currentIndex = list.indexOf( tab );
            var nextIndex = event.key === 'ArrowRight' ? currentIndex + 1 : currentIndex - 1;
            if ( nextIndex < 0 ) { nextIndex = list.length - 1; }
            if ( nextIndex >= list.length ) { nextIndex = 0; }
            var nextTab = list[ nextIndex ];
            activate( nextTab.getAttribute( 'data-compressly-tab' ) );
            nextTab.focus();
        } );
    } );

    var initial = '';
    if ( window.location.hash && window.location.hash.length > 1 ) {
        initial = window.location.hash.replace( /^#/, '' );
    }
    if ( ! initial ) {
        try {
            initial = window.sessionStorage.getItem( STORAGE_KEY ) || '';
        } catch ( e ) {}
    }
    if ( ! initial && tabs.length ) {
        initial = tabs[ 0 ].getAttribute( 'data-compressly-tab' );
    }
    if ( ! activate( initial ) && tabs.length ) {
        activate( tabs[ 0 ].getAttribute( 'data-compressly-tab' ) );
    }
} )();
