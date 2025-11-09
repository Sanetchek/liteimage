(function () {
    'use strict';

    var registerBlockType = wp.blocks.registerBlockType;
    var __ = wp.i18n.__;
    var Fragment = wp.element.Fragment;
    var useMemo = wp.element.useMemo;
    var useSelect = wp.data.useSelect;

    var InspectorControls = wp.blockEditor.InspectorControls;
    var MediaUpload = wp.blockEditor.MediaUpload;
    var MediaUploadCheck = wp.blockEditor.MediaUploadCheck;
    var useBlockProps = wp.blockEditor.useBlockProps;

    var PanelBody = wp.components.PanelBody;
    var Button = wp.components.Button;
    var TextControl = wp.components.TextControl;
    var Notice = wp.components.Notice;
    var SelectControl = wp.components.SelectControl;
    var __experimentalNumberControl = wp.components.__experimentalNumberControl;
    var NumberControl = __experimentalNumberControl || wp.components.TextControl;

    function toInt(value) {
        var parsed = parseInt(value, 10);
        return Number.isFinite(parsed) ? parsed : 0;
    }

    function AttributeList(props) {
        var items = props.items || [];
        var onChange = props.onChange;

        var handleAdd = function () {
            onChange([].concat(items, [{ key: '', value: '' }]));
        };

        var handleRemove = function (index) {
            var next = items.slice();
            next.splice(index, 1);
            onChange(next);
        };

        var handleUpdate = function (index, field, value) {
            var next = items.map(function (item, i) {
                if (i === index) {
                    var updated = Object.assign({}, item);
                    updated[field] = value;
                    return updated;
                }
                return item;
            });
            onChange(next);
        };

        return wp.element.createElement(
            Fragment,
            null,
            items.length === 0 &&
                wp.element.createElement(Notice, { status: 'info', isDismissible: false }, __('No attributes added yet.', 'liteimage')),
            items.map(function (item, index) {
                return wp.element.createElement(
                    PanelBody,
                    {
                        title: __('Attribute', 'liteimage') + ' #' + (index + 1),
                        initialOpen: true,
                        key: 'attr-' + index,
                    },
                    wp.element.createElement(TextControl, {
                        label: __('Attribute name', 'liteimage'),
                        help: __('Use valid HTML attributes like class, data-*, aria-*.', 'liteimage'),
                        value: item.key || '',
                        onChange: function (value) {
                            handleUpdate(index, 'key', value);
                        },
                    }),
                    wp.element.createElement(TextControl, {
                        label: __('Attribute value', 'liteimage'),
                        value: item.value || '',
                        onChange: function (value) {
                            handleUpdate(index, 'value', value);
                        },
                    }),
                    wp.element.createElement(Button, {
                        variant: 'secondary',
                        isDestructive: true,
                        onClick: function () {
                            handleRemove(index);
                        },
                    }, __('Remove attribute', 'liteimage'))
                );
            }),
            wp.element.createElement(
                Button,
                {
                    variant: 'secondary',
                    onClick: handleAdd,
                },
                __('Add attribute', 'liteimage')
            )
        );
    }

    function BreakpointList(props) {
        var items = props.items || [];
        var onChange = props.onChange;
        var itemLabel = props.itemLabel || __('Breakpoint', 'liteimage');

        var handleAdd = function () {
            onChange([].concat(items, [{ width: 0, imageWidth: 0, imageHeight: 0 }]));
        };

        var handleRemove = function (index) {
            var next = items.slice();
            next.splice(index, 1);
            onChange(next);
        };

        var handleUpdate = function (index, field, value) {
            var next = items.map(function (item, i) {
                if (i === index) {
                    var updated = Object.assign({}, item);
                    updated[field] = value;
                    return updated;
                }
                return item;
            });
            onChange(next);
        };

        return wp.element.createElement(
            Fragment,
            null,
            items.length === 0 &&
                wp.element.createElement(Notice, { status: 'info', isDismissible: false }, __('No breakpoints defined yet.', 'liteimage')),
            items.map(function (item, index) {
                return wp.element.createElement(
                    PanelBody,
                    {
                        title: itemLabel + ' #' + (index + 1),
                        initialOpen: true,
                        key: 'bp-' + index,
                    },
                    wp.element.createElement(NumberControl, {
                        label: __('Screen width (px)', 'liteimage'),
                        value: item.width || 0,
                        onChange: function (value) {
                            handleUpdate(index, 'width', toInt(value));
                        },
                    }),
                    wp.element.createElement(NumberControl, {
                        label: __('Image width (px)', 'liteimage'),
                        value: item.imageWidth || 0,
                        onChange: function (value) {
                            handleUpdate(index, 'imageWidth', toInt(value));
                        },
                    }),
                    wp.element.createElement(NumberControl, {
                        label: __('Image height (px)', 'liteimage'),
                        value: item.imageHeight || 0,
                        onChange: function (value) {
                            handleUpdate(index, 'imageHeight', toInt(value));
                        },
                    }),
                    wp.element.createElement(Button, {
                        variant: 'secondary',
                        isDestructive: true,
                        onClick: function () {
                            handleRemove(index);
                        },
                    }, __('Remove breakpoint', 'liteimage'))
                );
            }),
            wp.element.createElement(
                Button,
                {
                    variant: 'secondary',
                    onClick: handleAdd,
                },
                __('Add breakpoint', 'liteimage')
            )
        );
    }

    function MediaSelector(props) {
        var attributeKey = props.attributeKey;
        var attributes = props.attributes;
        var setAttributes = props.setAttributes;
        var label = props.label;
        var help = props.help;

        var mediaId = attributes[attributeKey] || 0;

        var media = useSelect(function (select) {
            if (!mediaId) {
                return null;
            }
            return select('core').getMedia(mediaId);
        }, [mediaId]);

        var previewSource = useMemo(function () {
            if (!media) {
                return null;
            }

            var details = media.media_details || {};
            var sizes = details.sizes || {};
            var preferred = ['thumbnail', 'medium', 'medium_large', 'large', 'full'];

            for (var i = 0; i < preferred.length; i++) {
                var key = preferred[i];
                if (sizes[key] && sizes[key].source_url) {
                    return sizes[key].source_url;
                }
            }

            var sizeKeys = Object.keys(sizes);
            if (sizeKeys.length > 0) {
                var firstKey = sizeKeys[0];
                if (sizes[firstKey] && sizes[firstKey].source_url) {
                    return sizes[firstKey].source_url;
                }
            }

            if (media.source_url) {
                return media.source_url;
            }

            if (media.url) {
                return media.url;
            }

            if (media.guid && media.guid.rendered) {
                return media.guid.rendered;
            }

            return null;
        }, [media]);

        var previewAlt = useMemo(function () {
            if (!media) {
                return '';
            }

            if (media.alt_text) {
                return media.alt_text;
            }

            if (media.title && media.title.rendered) {
                return media.title.rendered;
            }

            return '';
        }, [media]);

        var onSelect = function (item) {
            if (item && item.id) {
                var update = {};
                update[attributeKey] = item.id;
                setAttributes(update);
            }
        };

        var onRemove = function () {
            var update = {};
            update[attributeKey] = 0;
            setAttributes(update);
        };

        return wp.element.createElement(
            Fragment,
            null,
            wp.element.createElement('p', null, label),
            previewSource &&
                wp.element.createElement(
                    'div',
                    {
                        className: 'liteimage-media-preview',
                        style: { marginBottom: '12px' },
                    },
                    wp.element.createElement('img', {
                        src: previewSource,
                        alt: previewAlt,
                        style: { display: 'block', maxWidth: '100%', height: 'auto' },
                    })
                ),
            wp.element.createElement(
                MediaUploadCheck,
                null,
                wp.element.createElement(MediaUpload, {
                    allowedTypes: ['image'],
                    onSelect: onSelect,
                    value: mediaId,
                    render: function (renderProps) {
                        return wp.element.createElement(
                            Button,
                            {
                                variant: mediaId ? 'secondary' : 'primary',
                                onClick: renderProps.open,
                            },
                            mediaId ? __('Replace image', 'liteimage') : __('Select image', 'liteimage')
                        );
                    },
                })
            ),
            mediaId
                ? wp.element.createElement(
                      Button,
                      {
                          variant: 'link',
                          isDestructive: true,
                          onClick: onRemove,
                      },
                      __('Remove image', 'liteimage')
                  )
                : null,
            help ? wp.element.createElement('p', { className: 'components-help' }, help) : null
        );
    }

    registerBlockType('liteimage/image', {
        edit: function (props) {
            var attributes = props.attributes;
            var setAttributes = props.setAttributes;
            var desktopImageId = attributes.desktopImageId || 0;
            var thumb = attributes.thumb || { width: 0, height: 0 };
            var breakpointMode = attributes.breakpointMode;
            var breakpoints = attributes.breakpoints;
            var legacyMin = attributes.minBreakpoints;
            var legacyMax = attributes.maxBreakpoints;

            if (!Array.isArray(breakpoints)) {
                if (Array.isArray(legacyMin) && legacyMin.length > 0) {
                    breakpoints = legacyMin;
                    if (!breakpointMode) {
                        breakpointMode = 'min';
                    }
                } else if (Array.isArray(legacyMax) && legacyMax.length > 0) {
                    breakpoints = legacyMax;
                    if (!breakpointMode) {
                        breakpointMode = 'max';
                    }
                } else {
                    breakpoints = [];
                }
            }

            if (!breakpointMode) {
                breakpointMode = 'min';
            }

            var breakpointItemLabel = breakpointMode === 'max' ? __('Max-width breakpoint', 'liteimage') : __('Min-width breakpoint', 'liteimage');

            var hasImage = desktopImageId > 0;

            var desktopPreview = useSelect(function (select) {
                if (!desktopImageId) {
                    return null;
                }
                return select('core').getMedia(desktopImageId);
            }, [desktopImageId]);

            var previewUrl = useMemo(function () {
                if (!desktopPreview) {
                    return null;
                }
                if (desktopPreview.source_url) {
                    return desktopPreview.source_url;
                }
                if (desktopPreview.media_details && desktopPreview.media_details.sizes && desktopPreview.media_details.sizes.medium) {
                    return desktopPreview.media_details.sizes.medium.source_url;
                }
                return null;
            }, [desktopPreview]);

            var blockClassName = hasImage ? 'liteimage-block liteimage-block-preview' : 'liteimage-block liteimage-block-placeholder';
            var blockProps = useBlockProps({ className: blockClassName });

            return wp.element.createElement(
                Fragment,
                null,
                wp.element.createElement(
                    InspectorControls,
                    null,
                    wp.element.createElement(
                        PanelBody,
                        { title: __('Source images', 'liteimage'), initialOpen: true },
                        wp.element.createElement(MediaSelector, {
                            attributeKey: 'desktopImageId',
                            attributes: attributes,
                            setAttributes: setAttributes,
                            label: __('Desktop image', 'liteimage'),
                            help: __('This image is used for desktop breakpoints and as a fallback.', 'liteimage'),
                        }),
                        wp.element.createElement(MediaSelector, {
                            attributeKey: 'mobileImageId',
                            attributes: attributes,
                            setAttributes: setAttributes,
                            label: __('Mobile image', 'liteimage'),
                            help: __('Optional image used for breakpoints below the mobile breakpoint.', 'liteimage'),
                        })
                    ),
                    wp.element.createElement(
                        PanelBody,
                        { title: __('Default size', 'liteimage'), initialOpen: false },
                        wp.element.createElement(NumberControl, {
                            label: __('Width (px)', 'liteimage'),
                            value: thumb.width || 0,
                            onChange: function (value) {
                                var next = Object.assign({}, thumb, { width: toInt(value) });
                                setAttributes({ thumb: next });
                            },
                        }),
                        wp.element.createElement(NumberControl, {
                            label: __('Height (px)', 'liteimage'),
                            value: thumb.height || 0,
                            onChange: function (value) {
                                var next = Object.assign({}, thumb, { height: toInt(value) });
                                setAttributes({ thumb: next });
                            },
                        }),
                        wp.element.createElement(
                            Notice,
                            { status: 'info', isDismissible: false },
                            __('Set one dimension to 0 to maintain aspect ratio automatically.', 'liteimage')
                        )
                    ),
                    wp.element.createElement(
                        PanelBody,
                        { title: __('Screen breakpoints', 'liteimage'), initialOpen: false },
                        wp.element.createElement(SelectControl, {
                            label: __('Breakpoint type', 'liteimage'),
                            value: breakpointMode,
                            options: [
                                { label: __('Min-width (>=)', 'liteimage'), value: 'min' },
                                { label: __('Max-width (<=)', 'liteimage'), value: 'max' },
                            ],
                            onChange: function (value) {
                                setAttributes({ breakpointMode: value });
                            },
                        }),
                        wp.element.createElement(BreakpointList, {
                            itemLabel: breakpointItemLabel,
                            items: breakpoints,
                            onChange: function (value) {
                                setAttributes({ breakpoints: value });
                            },
                        })
                    ),
                    wp.element.createElement(
                        PanelBody,
                        { title: __('HTML attributes', 'liteimage'), initialOpen: false },
                        wp.element.createElement(AttributeList, {
                            items: attributes.htmlAttributes || [],
                            onChange: function (value) {
                                setAttributes({ htmlAttributes: value });
                            },
                        })
                    )
                ),
                hasImage
                    ? wp.element.createElement(
                          'div',
                          blockProps,
                          previewUrl
                              ? wp.element.createElement('img', {
                                    src: previewUrl,
                                    alt: __('LiteImage preview', 'liteimage'),
                                    style: { maxWidth: '100%', height: 'auto' },
                                })
                              : wp.element.createElement('div', null, __('Image preview loading…', 'liteimage'))
                      )
                    : wp.element.createElement(
                          'div',
                          blockProps,
                          wp.element.createElement(
                              MediaUploadCheck,
                              null,
                              wp.element.createElement(MediaUpload, {
                                  allowedTypes: ['image'],
                                  onSelect: function (item) {
                                      if (item && item.id) {
                                          setAttributes({ desktopImageId: item.id });
                                      }
                                  },
                                  render: function (renderProps) {
                                      return wp.element.createElement(
                                          Button,
                                          {
                                              variant: 'primary',
                                              onClick: renderProps.open,
                                          },
                                          __('Select image', 'liteimage')
                                      );
                                  },
                              })
                          ),
                          wp.element.createElement(
                              'p',
                              { className: 'components-help' },
                              __('After selecting an image you can fine-tune responsive settings in the sidebar.', 'liteimage')
                          )
                      )
            );
        },
        save: function () {
            return null;
        },
    });
})();

