<?php
/*************************************************************************************/
/*                                                                                   */
/*      Thelia	                                                                     */
/*                                                                                   */
/*      Copyright (c) OpenStudio                                                     */
/*	    email : info@thelia.net                                                      */
/*      web : http://www.thelia.net                                                  */
/*                                                                                   */
/*      This program is free software; you can redistribute it and/or modify         */
/*      it under the terms of the GNU General Public License as published by         */
/*      the Free Software Foundation; either version 3 of the License                */
/*                                                                                   */
/*      This program is distributed in the hope that it will be useful,              */
/*      but WITHOUT ANY WARRANTY; without even the implied warranty of               */
/*      MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the                */
/*      GNU General Public License for more details.                                 */
/*                                                                                   */
/*      You should have received a copy of the GNU General Public License            */
/*	    along with this program. If not, see <http://www.gnu.org/licenses/>.         */
/*                                                                                   */
/*************************************************************************************/
namespace Front\Controller;

use Front\Front;
use Propel\Runtime\ActiveQuery\Criteria;
use Propel\Runtime\Exception\PropelException;
use Symfony\Component\HttpFoundation\File\MimeType\MimeTypeGuesser;
use Thelia\Core\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Thelia\Controller\Front\BaseFrontController;
use Thelia\Core\Event\Order\OrderEvent;
use Thelia\Core\Event\Product\VirtualProductOrderDownloadResponseEvent;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Core\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Response as BaseResponse;
use Thelia\Exception\TheliaProcessException;
use Thelia\Form\Exception\FormValidationException;
use Thelia\Form\OrderDelivery;
use Thelia\Form\OrderPayment;
use Thelia\Log\Tlog;
use Thelia\Model\Address;
use Thelia\Model\AddressQuery;
use Thelia\Model\AreaDeliveryModuleQuery;
use Thelia\Model\ConfigQuery;
use Thelia\Model\ModuleQuery;
use Thelia\Model\Order;
use Thelia\Model\OrderProductQuery;
use Thelia\Model\OrderQuery;
use Thelia\Module\AbstractDeliveryModule;

/**
 * Class OrderController
 * @package Thelia\Controller\Front
 * @author Etienne Roudeix <eroudeix@openstudio.fr>
 */
class OrderController extends BaseFrontController
{
    /**
     * Check if the cart contains only virtual products.
     */
    public function deliverView()
    {
        $this->checkAuth();
        $this->checkCartNotEmpty();

        // check if the cart contains only virtual products
        $cart = $this->getSession()->getSessionCart($this->getDispatcher());

        if ($cart->isVirtual()) {
            // get the virtual product module
            $customer = $this->getSecurityContext()->getCustomerUser();

            $deliveryAddress = AddressQuery::create()
                ->filterByCustomerId($customer->getId())
                ->orderByIsDefault(Criteria::DESC)
                ->findOne();

            if (null !== $deliveryAddress) {
                $deliveryModule = ModuleQuery::create()->retrieveVirtualProductDelivery($this->container);

                if (false === $deliveryModule) {
                    Tlog::getInstance()->error(
                        $this->getTranslator()->trans(
                            "To enable the virtual product feature, the VirtualProductDelivery module should be activated",
                            [],
                            Front::MESSAGE_DOMAIN
                        )
                    );
                } elseif (count($deliveryModule) == 1) {
                    return $this->registerVirtualProductDelivery($deliveryModule[0], $deliveryAddress);
                }
            }
        }

        return $this->render('order-delivery');
    }

    /**
     * @param AbstractDeliveryModule $moduleInstance
     * @param Address $deliveryAddress
     * @return \Symfony\Component\HttpFoundation\Response
     */
    private function registerVirtualProductDelivery($moduleInstance, $deliveryAddress)
    {
        /* get postage amount */
        $deliveryModule = $moduleInstance->getModuleModel();
        $postage = $moduleInstance->getPostage($deliveryAddress->getCountry());

        $orderEvent = $this->getOrderEvent();
        $orderEvent->setDeliveryAddress($deliveryAddress->getId());
        $orderEvent->setDeliveryModule($deliveryModule->getId());
        $orderEvent->setPostage($postage);

        $this->getDispatcher()->dispatch(TheliaEvents::ORDER_SET_DELIVERY_ADDRESS, $orderEvent);
        $this->getDispatcher()->dispatch(TheliaEvents::ORDER_SET_DELIVERY_MODULE, $orderEvent);
        $this->getDispatcher()->dispatch(TheliaEvents::ORDER_SET_POSTAGE, $orderEvent);

        return $this->generateRedirectFromRoute("order.invoice");
    }

    /**
     * set delivery address
     * set delivery module
     */
    public function deliver()
    {
        $this->checkAuth();
        $this->checkCartNotEmpty();

        $message = false;

        $orderDelivery = new OrderDelivery($this->getRequest());

        try {
            $form = $this->validateForm($orderDelivery, "post");

            $deliveryAddressId = $form->get("delivery-address")->getData();
            $deliveryModuleId = $form->get("delivery-module")->getData();
            $deliveryAddress = AddressQuery::create()->findPk($deliveryAddressId);
            $deliveryModule = ModuleQuery::create()->findPk($deliveryModuleId);

            /* check that the delivery address belongs to the current customer */
            if ($deliveryAddress->getCustomerId() !== $this->getSecurityContext()->getCustomerUser()->getId()) {
                throw new \Exception(
                    $this->getTranslator()->trans(
                        "Delivery address does not belong to the current customer",
                        [],
                        Front::MESSAGE_DOMAIN
                    )
                );
            }

            /* check that the delivery module fetches the delivery address area */
            if (AreaDeliveryModuleQuery::create()
                ->filterByAreaId($deliveryAddress->getCountry()->getAreaId())
                ->filterByDeliveryModuleId($deliveryModuleId)
                ->count() == 0) {
                throw new \Exception(
                    $this->getTranslator()->trans(
                        "Delivery module cannot be use with selected delivery address",
                        [],
                        Front::MESSAGE_DOMAIN
                    )
                );
            }

            /* get postage amount */
            $moduleInstance = $deliveryModule->getDeliveryModuleInstance($this->container);

            $postage = $moduleInstance->getPostage($deliveryAddress->getCountry());

            $orderEvent = $this->getOrderEvent();
            $orderEvent->setDeliveryAddress($deliveryAddressId);
            $orderEvent->setDeliveryModule($deliveryModuleId);
            $orderEvent->setPostage($postage);

            $this->getDispatcher()->dispatch(TheliaEvents::ORDER_SET_DELIVERY_ADDRESS, $orderEvent);
            $this->getDispatcher()->dispatch(TheliaEvents::ORDER_SET_DELIVERY_MODULE, $orderEvent);
            $this->getDispatcher()->dispatch(TheliaEvents::ORDER_SET_POSTAGE, $orderEvent);

            return $this->generateRedirectFromRoute("order.invoice");

        } catch (FormValidationException $e) {
            $message = $this->getTranslator()->trans(
                "Please check your input: %s",
                ['%s' => $e->getMessage()],
                Front::MESSAGE_DOMAIN
            );
        } catch (PropelException $e) {
            $this->getParserContext()->setGeneralError($e->getMessage());
        } catch (\Exception $e) {
            $message = $this->getTranslator()->trans(
                "Sorry, an error occured: %s",
                ['%s' => $e->getMessage()],
                Front::MESSAGE_DOMAIN
            );
        }

        if ($message !== false) {
            Tlog::getInstance()->error(
                sprintf("Error during order delivery process : %s. Exception was %s", $message, $e->getMessage())
            );

            $orderDelivery->setErrorMessage($message);

            $this->getParserContext()
                ->addForm($orderDelivery)
                ->setGeneralError($message)
            ;
        }
    }

    /**
     * set invoice address
     * set payment module
     */
    public function invoice()
    {
        $this->checkAuth();
        $this->checkCartNotEmpty();
        $this->checkValidDelivery();

        $message = false;

        $orderPayment = new OrderPayment($this->getRequest());

        try {
            $form = $this->validateForm($orderPayment, "post");

            $invoiceAddressId = $form->get("invoice-address")->getData();
            $paymentModuleId = $form->get("payment-module")->getData();

            /* check that the invoice address belongs to the current customer */
            $invoiceAddress = AddressQuery::create()->findPk($invoiceAddressId);
            if ($invoiceAddress->getCustomerId() !== $this->getSecurityContext()->getCustomerUser()->getId()) {
                throw new \Exception(
                    $this->getTranslator()->trans(
                        "Invoice address does not belong to the current customer",
                        [],
                        Front::MESSAGE_DOMAIN
                    )
                );
            }

            $orderEvent = $this->getOrderEvent();
            $orderEvent->setInvoiceAddress($invoiceAddressId);
            $orderEvent->setPaymentModule($paymentModuleId);

            $this->getDispatcher()->dispatch(TheliaEvents::ORDER_SET_INVOICE_ADDRESS, $orderEvent);
            $this->getDispatcher()->dispatch(TheliaEvents::ORDER_SET_PAYMENT_MODULE, $orderEvent);

            return $this->generateRedirectFromRoute("order.payment.process");

        } catch (FormValidationException $e) {
            $message = $this->getTranslator()->trans(
                "Please check your input: %s",
                ['%s' => $e->getMessage()],
                Front::MESSAGE_DOMAIN
            );
        } catch (PropelException $e) {
            $this->getParserContext()->setGeneralError($e->getMessage());
        } catch (\Exception $e) {
            $message = $this->getTranslator()->trans(
                "Sorry, an error occured: %s",
                ['%s' => $e->getMessage()],
                Front::MESSAGE_DOMAIN
            );
        }

        if ($message !== false) {
            Tlog::getInstance()->error(
                sprintf("Error during order payment process : %s. Exception was %s", $message, $e->getMessage())
            );

            $orderPayment->setErrorMessage($message);

            $this->getParserContext()
                ->addForm($orderPayment)
                ->setGeneralError($message)
            ;
        }
    }

    public function pay()
    {
        /* check customer */
        $this->checkAuth();

        /* check cart count */
        $this->checkCartNotEmpty();
        
        /* check stock not empty */
        if (true === ConfigQuery::checkAvailableStock()) {
            if (null !== $response = $this->checkStockNotEmpty()) {
                return $response;
            }
        }
        
        /* check delivery address and module */
        $this->checkValidDelivery();

        /* check invoice address and payment module */
        $this->checkValidInvoice();

        $orderEvent = $this->getOrderEvent();

        $this->getDispatcher()->dispatch(TheliaEvents::ORDER_PAY, $orderEvent);

        $placedOrder = $orderEvent->getPlacedOrder();

        if (null !== $placedOrder && null !== $placedOrder->getId()) {
            /* order has been placed */
            if ($orderEvent->hasResponse()) {
                return $orderEvent->getResponse();
            } else {
                return $this->generateRedirectFromRoute(
                    'order.placed',
                    [],
                    ['order_id' => $orderEvent->getPlacedOrder()->getId()]
                );
            }
        } else {
            /* order has not been placed */
            return $this->generateRedirectFromRoute('cart.view');
        }
    }

    public function orderPlaced($order_id)
    {
        /* check if the placed order matched the customer */
        $placedOrder = OrderQuery::create()->findPk(
            $this->getRequest()->attributes->get('order_id')
        );

        if (null === $placedOrder) {
            throw new TheliaProcessException(
                $this->getTranslator()->trans(
                    "No placed order",
                    [],
                    Front::MESSAGE_DOMAIN
                ),
                TheliaProcessException::NO_PLACED_ORDER,
                $placedOrder
            );
        }

        $customer = $this->getSecurityContext()->getCustomerUser();

        if (null === $customer || $placedOrder->getCustomerId() !== $customer->getId()) {
            throw new TheliaProcessException(
                $this->getTranslator()->trans(
                    "Received placed order id does not belong to the current customer",
                    [],
                    Front::MESSAGE_DOMAIN
                ),
                TheliaProcessException::PLACED_ORDER_ID_BAD_CURRENT_CUSTOMER,
                $placedOrder
            );
        }

        $this->getDispatcher()->dispatch(TheliaEvents::ORDER_CART_CLEAR, $this->getOrderEvent());

        $this->getParserContext()->set("placed_order_id", $placedOrder->getId());
    }


    public function orderFailed($order_id, $message)
    {
        /* check if the placed order matched the customer */
        $failedOrder = OrderQuery::create()->findPk(
            $this->getRequest()->attributes->get('order_id')
        );

        if (null === $failedOrder) {
            throw new TheliaProcessException("No failed order", TheliaProcessException::NO_PLACED_ORDER, $failedOrder);
        }

        $customer = $this->getSecurityContext()->getCustomerUser();

        if (null === $customer || $failedOrder->getCustomerId() !== $customer->getId()) {
            throw new TheliaProcessException(
                $this->getTranslator()->trans(
                    "Received failed order id does not belong to the current customer",
                    [],
                    Front::MESSAGE_DOMAIN
                ),
                TheliaProcessException::PLACED_ORDER_ID_BAD_CURRENT_CUSTOMER,
                $failedOrder
            );
        }

        $this->getParserContext()
            ->set("failed_order_id", $failedOrder->getId())
            ->set("failed_order_message", $message)
        ;
    }

    protected function getOrderEvent()
    {
        $order = $this->getOrder($this->getRequest());

        return new OrderEvent($order);
    }

    public function getOrder(Request $request)
    {
        $session = $request->getSession();

        if (null !== $order = $session->getOrder()) {
            return $order;
        }

        $order = new Order();

        $session->setOrder($order);

        return $order;
    }


    public function viewAction($order_id)
    {
        $this->checkOrderCustomer($order_id);

        return $this->render('account-order', ['order_id' => $order_id]);
    }

    public function generateInvoicePdf($order_id)
    {
        $this->checkOrderCustomer($order_id);


        return $this->generateOrderPdf($order_id, ConfigQuery::read('pdf_invoice_file', 'invoice'));
    }

    public function generateDeliveryPdf($order_id)
    {
        $this->checkOrderCustomer($order_id);

        return $this->generateOrderPdf($order_id, ConfigQuery::read('pdf_delivery_file', 'delivery'));
    }

    public function downloadVirtualProduct($order_product_id)
    {
        if (null !== $orderProduct = OrderProductQuery::create()->findPk($order_product_id)) {
            $order = $orderProduct->getOrder();

            if ($order->isPaid()) {
                // check customer
                $this->checkOrderCustomer($order->getId());

                $virtualProductEvent = new VirtualProductOrderDownloadResponseEvent($orderProduct);
                $this->getDispatcher()->dispatch(
                    TheliaEvents::VIRTUAL_PRODUCT_ORDER_DOWNLOAD_RESPONSE,
                    $virtualProductEvent
                );

                $response = $virtualProductEvent->getResponse();

                if (!$response instanceof BaseResponse) {
                    throw new \RuntimeException('A Response must be added in the event TheliaEvents::VIRTUAL_PRODUCT_ORDER_DOWNLOAD_RESPONSE');
                }

                return $response;
            }
        }

        throw new AccessDeniedHttpException();

    }

    private function checkOrderCustomer($order_id)
    {
        $this->checkAuth();

        $order = OrderQuery::create()->findPk($order_id);
        $valid = true;
        if ($order) {
            $customerOrder = $order->getCustomer();
            $customer = $this->getSecurityContext()->getCustomerUser();

            if ($customerOrder->getId() != $customer->getId()) {
                $valid = false;
            }
        } else {
            $valid = false;
        }

        if (false === $valid) {
            throw new AccessDeniedHttpException();
        }
    }

    public function getDeliveryModuleListAjaxAction()
    {
        $country = $this->getRequest()->get(
            'country_id',
            $this->container->get('thelia.taxEngine')->getDeliveryCountry()->getId()
        );

        $this->checkXmlHttpRequest();
        $args = array('country' => $country);

        return $this->render('ajax/order-delivery-module-list', $args);
    }
    
    private function checkStockNotEmpty()
    {
        $cart = $this->getSession()->getSessionCart($this->getDispatcher());
        $cartItems = $cart->getCartItems();
        $flagQuantity = 0;
        foreach ($cartItems as $cartItem) {
            $pse = $cartItem->getProductSaleElements();
            if ($pse->getQuantity() <= 0) {
                $flagQuantity = 1;
            }
        }
        if ($flagQuantity == 1) {
            return $this->generateRedirectFromRoute('cart.view');
        } else {
            return null;
        }
    }
}
